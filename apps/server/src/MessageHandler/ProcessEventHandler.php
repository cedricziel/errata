<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Issue;
use App\Message\ProcessEvent;
use App\Repository\IssueRepository;
use App\Repository\ProjectRepository;
use App\Service\FingerprintService;
use App\Service\Parquet\ParquetWriterService;
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
        $this->logger->info('Processing event', [
            'project_id' => $message->projectId,
            'event_type' => $message->eventData['event_type'] ?? 'unknown',
        ]);

        // Find the project
        $project = $this->projectRepository->findByPublicId($message->projectId);

        if (null === $project) {
            $this->logger->error('Project not found', ['project_id' => $message->projectId]);

            return;
        }

        // Prepare the event data
        $eventData = $this->prepareEventData($message->eventData, $message->projectId, $message->environment);

        // Generate fingerprint
        $fingerprint = $this->fingerprintService->generateFingerprint($eventData);
        $eventData['fingerprint'] = $fingerprint;

        // Find or create the issue
        $issue = $this->findOrCreateIssue($project, $eventData, $fingerprint);

        // Write event to Parquet
        try {
            $this->parquetWriter->writeEvent($eventData);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to write event to Parquet', [
                'error' => $e->getMessage(),
                'event_id' => $eventData['event_id'],
            ]);
        }

        $this->logger->info('Event processed successfully', [
            'event_id' => $eventData['event_id'],
            'issue_id' => $issue->getPublicId()?->toRfc4122(),
            'fingerprint' => $fingerprint,
        ]);
    }

    /**
     * Prepare the event data with required fields.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function prepareEventData(array $data, string $projectId, string $environment): array
    {
        $now = (int) (microtime(true) * 1000);

        return array_merge($data, [
            'event_id' => Uuid::v7()->toRfc4122(),
            'timestamp' => $data['timestamp'] ?? $now,
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
}
