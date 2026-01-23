<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Issue;
use App\Message\ProcessEventBatch;
use App\Repository\IssueRepository;
use App\Repository\ProjectRepository;
use App\Service\FingerprintService;
use App\Service\Parquet\ParquetWriterService;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Handler for processing batches of events efficiently.
 *
 * Processes all events in a batch, generating fingerprints and issues,
 * then writes all events to a single parquet file.
 */
#[AsMessageHandler]
final class ProcessEventBatchHandler
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly IssueRepository $issueRepository,
        private readonly FingerprintService $fingerprintService,
        private readonly ParquetWriterService $parquetWriter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessEventBatch $message): void
    {
        $span = $this->startSpan('process_event_batch');
        $span->setAttribute('project.id', $message->projectId);
        $span->setAttribute('batch.size', count($message->events));

        try {
            $this->logger->info('Processing event batch', [
                'project_id' => $message->projectId,
                'batch_size' => count($message->events),
            ]);

            // Find the project once for the entire batch
            $project = $this->projectRepository->findByPublicId($message->projectId);

            if (null === $project) {
                $this->logger->error('Project not found', ['project_id' => $message->projectId]);
                $span->setStatus(StatusCode::STATUS_ERROR, 'Project not found');

                return;
            }

            $organizationId = $project->getOrganization()->getPublicId()?->toRfc4122();
            $processedEvents = [];

            // Process each event in the batch
            foreach ($message->events as $index => $eventData) {
                $eventSpan = $this->startSpan('process_event_in_batch');
                $eventSpan->setAttribute('batch.index', $index);

                try {
                    // Prepare the event data
                    $prepared = $this->prepareEventData(
                        $eventData,
                        $organizationId,
                        $message->projectId,
                        $message->environment
                    );

                    // Generate fingerprint
                    $fingerprint = $this->fingerprintService->generateFingerprint($prepared);
                    $prepared['fingerprint'] = $fingerprint;
                    $eventSpan->setAttribute('fingerprint', $fingerprint);

                    // Find or create the issue
                    $issue = $this->findOrCreateIssue($project, $prepared, $fingerprint);
                    $eventSpan->setAttribute('issue.id', $issue->getPublicId()?->toRfc4122());

                    $processedEvents[] = $prepared;
                    $eventSpan->setStatus(StatusCode::STATUS_OK);
                } catch (\Throwable $e) {
                    $eventSpan->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
                    $eventSpan->recordException($e);

                    $this->logger->error('Failed to process event in batch', [
                        'batch_index' => $index,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue processing remaining events
                } finally {
                    $eventSpan->end();
                }
            }

            // Write all processed events to parquet in one call
            if (!empty($processedEvents)) {
                try {
                    $this->traceOperation('parquet.write_batch', function () use ($processedEvents): void {
                        $this->parquetWriter->writeEvents($processedEvents);
                    });
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to write batch to Parquet', [
                        'error' => $e->getMessage(),
                        'event_count' => count($processedEvents),
                    ]);
                    // Don't rethrow - events are still processed for issue tracking
                }
            }

            $span->setAttribute('batch.processed', count($processedEvents));
            $span->setStatus(StatusCode::STATUS_OK);

            $this->logger->info('Event batch processed successfully', [
                'project_id' => $message->projectId,
                'processed_count' => count($processedEvents),
                'total_count' => count($message->events),
            ]);
        } catch (\Throwable $e) {
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->recordException($e);

            throw $e;
        } finally {
            $span->end();
        }
    }

    /**
     * Prepare the event data with required fields.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function prepareEventData(array $data, ?string $organizationId, string $projectId, string $environment): array
    {
        $now = (int) (microtime(true) * 1000);

        return array_merge($data, [
            'event_id' => Uuid::v7()->toRfc4122(),
            'timestamp' => $data['timestamp'] ?? $now,
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'environment' => $data['environment'] ?? $environment,
        ]);
    }

    /**
     * Find an existing issue or create a new one.
     *
     * @param array<string, mixed> $eventData
     */
    private function findOrCreateIssue(
        \App\Entity\Project $project,
        array $eventData,
        string $fingerprint,
    ): Issue {
        $issue = $this->issueRepository->findByFingerprint($project, $fingerprint);

        if (null !== $issue) {
            // Update existing issue
            $issue->incrementOccurrenceCount();

            // Track unique users if user_id is present
            if (!empty($eventData['user_id'])) {
                // In a production system, you'd track unique users properly
                // For MVP, we just increment the counter for new sessions
                if (!empty($eventData['session_id'])) {
                    $issue->incrementAffectedUsers();
                }
            }

            // Reopen if it was resolved
            if ($issue->isResolved()) {
                $issue->setStatus(Issue::STATUS_OPEN);
            }

            $this->issueRepository->save($issue, true);

            return $issue;
        }

        // Create new issue
        $issue = new Issue();
        $issue->setProject($project);
        $issue->setFingerprint($fingerprint);
        $issue->setType($this->mapEventTypeToIssueType($eventData['event_type'] ?? 'error'));
        $issue->setTitle($this->fingerprintService->extractTitle($eventData));
        $issue->setCulprit($this->fingerprintService->extractCulprit($eventData));
        $issue->setSeverity($eventData['severity'] ?? null);

        // Initial metadata
        $issue->setMetadata([
            'first_app_version' => $eventData['app_version'] ?? null,
            'first_os_version' => $eventData['os_version'] ?? null,
            'first_device_model' => $eventData['device_model'] ?? null,
        ]);

        if (!empty($eventData['user_id'])) {
            $issue->setAffectedUsers(1);
        }

        $this->issueRepository->save($issue, true);

        return $issue;
    }

    /**
     * Map event type to issue type.
     */
    private function mapEventTypeToIssueType(string $eventType): string
    {
        return match ($eventType) {
            'crash' => Issue::TYPE_CRASH,
            'error' => Issue::TYPE_ERROR,
            'log' => Issue::TYPE_LOG,
            default => Issue::TYPE_ERROR,
        };
    }

    private function startSpan(string $name): SpanInterface
    {
        return Globals::tracerProvider()->getTracer('errata')
            ->spanBuilder($name)
            ->startSpan();
    }

    /**
     * Execute an operation within a traced span.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function traceOperation(string $name, callable $callback): mixed
    {
        $span = Globals::tracerProvider()->getTracer('errata')
            ->spanBuilder($name)
            ->startSpan();

        $scope = $span->activate();

        try {
            $result = $callback();
            $span->setStatus(StatusCode::STATUS_OK);

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->recordException($e);

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
