<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Issue;
use App\Message\ProcessEvent;
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
 * Handler for processing incoming events.
 */
#[AsMessageHandler]
class ProcessEventHandler
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly IssueRepository $issueRepository,
        private readonly FingerprintService $fingerprintService,
        private readonly ParquetWriterService $parquetWriter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessEvent $message): void
    {
        $span = $this->startSpan('process_event');
        $eventType = $message->eventData['event_type'] ?? 'unknown';

        $span->setAttribute('project.id', $message->projectId);
        $span->setAttribute('event.type', $eventType);

        try {
            $this->logger->info('Processing event', [
                'project_id' => $message->projectId,
                'event_type' => $eventType,
            ]);

            // Find the project
            $project = $this->projectRepository->findByPublicId($message->projectId);

            if (null === $project) {
                $this->logger->error('Project not found', ['project_id' => $message->projectId]);
                $span->setStatus(StatusCode::STATUS_ERROR, 'Project not found');

                return;
            }

            // Get organization ID for Hive partitioning
            $organizationId = $project->getOrganization()->getPublicId()?->toRfc4122();

            // Prepare the event data
            $eventData = $this->prepareEventData($message->eventData, $organizationId, $message->projectId, $message->environment);

            // Generate fingerprint
            $fingerprint = $this->traceOperation('fingerprint.generate', function () use ($eventData) {
                return $this->fingerprintService->generateFingerprint($eventData);
            });
            $eventData['fingerprint'] = $fingerprint;
            $span->setAttribute('fingerprint', $fingerprint);

            // Find or create the issue
            $issue = $this->traceOperation('issue.find_or_create', function () use ($project, $eventData, $fingerprint) {
                return $this->findOrCreateIssue($project, $eventData, $fingerprint);
            });
            $span->setAttribute('issue.id', $issue->getPublicId()?->toRfc4122());

            // Add event to Parquet buffer (will be flushed on worker shutdown or when buffer is full)
            try {
                $this->traceOperation('parquet.buffer', function () use ($eventData): void {
                    $this->parquetWriter->addEvent($eventData);
                });
            } catch (\Throwable $e) {
                $this->logger->error('Failed to buffer event for Parquet', [
                    'error' => $e->getMessage(),
                    'event_id' => $eventData['event_id'],
                ]);
                // Don't rethrow - we still consider the event processed
            }

            $span->setStatus(StatusCode::STATUS_OK);

            $this->logger->info('Event processed successfully', [
                'event_id' => $eventData['event_id'],
                'issue_id' => $issue->getPublicId()?->toRfc4122(),
                'fingerprint' => $fingerprint,
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
