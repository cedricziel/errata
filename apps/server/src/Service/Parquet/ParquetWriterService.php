<?php

declare(strict_types=1);

namespace App\Service\Parquet;

use App\Service\Storage\StorageFactory;
use Flow\Filesystem\FilesystemTable;
use Flow\Filesystem\Path;
use Flow\Parquet\Writer;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service for writing wide events to Parquet files.
 *
 * Supports both local filesystem and S3-compatible storage.
 */
class ParquetWriterService
{
    private const BATCH_SIZE = 1000;

    /** @var array<string, mixed>[] */
    private array $buffer = [];

    private readonly FilesystemTable $filesystemTable;
    private readonly string $basePath;

    public function __construct(
        private readonly StorageFactory $storageFactory,
        private readonly LoggerInterface $logger,
    ) {
        $this->filesystemTable = $this->storageFactory->createFilesystemTable();
        $this->basePath = $this->storageFactory->getBasePath();
    }

    /**
     * Add an event to the write buffer.
     *
     * @param array<string, mixed> $event
     */
    public function addEvent(array $event): void
    {
        $this->buffer[] = WideEventSchema::normalize($event);

        if (count($this->buffer) >= self::BATCH_SIZE) {
            $this->flush();
        }
    }

    /**
     * Add multiple events to the write buffer.
     *
     * @param array<array<string, mixed>> $events
     */
    public function addEvents(array $events): void
    {
        foreach ($events as $event) {
            $this->addEvent($event);
        }
    }

    /**
     * Flush the buffer to a Parquet file.
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        try {
            $this->writeEvents($this->buffer);
            $this->buffer = [];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to flush events to Parquet', [
                'error' => $e->getMessage(),
                'event_count' => count($this->buffer),
            ]);
            throw $e;
        }
    }

    /**
     * Write events directly to a Parquet file.
     *
     * @param array<array<string, mixed>> $events
     */
    public function writeEvents(array $events): string
    {
        if (empty($events)) {
            throw new \InvalidArgumentException('No events to write');
        }

        $span = $this->startSpan('parquet.write_events');

        try {
            // Determine the path based on the first event
            $firstEvent = $events[0];
            $organizationId = $firstEvent['organization_id'] ?? 'unknown';
            $projectId = $firstEvent['project_id'] ?? 'unknown';
            $eventType = $firstEvent['event_type'] ?? 'unknown';
            $timestamp = $firstEvent['timestamp'] ?? (int) (microtime(true) * 1000);

            $span->setAttribute('organization.id', $organizationId);
            $span->setAttribute('project.id', $projectId);
            $span->setAttribute('event.type', $eventType);
            $span->setAttribute('event.count', count($events));
            $span->setAttribute('storage.type', $this->storageFactory->getStorageType());

            $filePath = $this->getFilePath($organizationId, $projectId, $eventType, $timestamp);
            $this->ensureDirectoryExists(dirname($filePath));

            $span->setAttribute('file.path', $filePath);

            $writer = new Writer();
            $schema = WideEventSchema::getSchema();

            // For S3/memory storage, use streams; for local, use the standard open method
            if ($this->storageFactory->requiresStreamOperations()) {
                $path = Path::realpath($filePath);
                $filesystem = $this->filesystemTable->for($path);
                $stream = $filesystem->writeTo($path);
                $writer->openForStream($stream, $schema);
            } else {
                $writer->open($filePath, $schema);
            }

            foreach ($events as $event) {
                $normalized = WideEventSchema::normalize($event);
                $writer->writeRow($this->eventToRow($normalized));
            }

            $writer->close();

            $span->setStatus(StatusCode::STATUS_OK);

            $this->logger->info('Wrote events to Parquet file', [
                'file' => $filePath,
                'event_count' => count($events),
                'storage_type' => $this->storageFactory->getStorageType(),
            ]);

            return $filePath;
        } catch (\Throwable $e) {
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->recordException($e);

            throw $e;
        } finally {
            $span->end();
        }
    }

    /**
     * Write a single event directly (bypasses buffer).
     *
     * @param array<string, mixed> $event
     */
    public function writeEvent(array $event): string
    {
        return $this->writeEvents([$event]);
    }

    /**
     * Get the file path for a given organization, project, event type, and timestamp using Hive-style partitioning.
     *
     * Path format: organization_id={org}/project_id={proj}/event_type={type}/dt={YYYY-MM-DD}/events_{HHmmss}_{UUID}.parquet
     */
    public function getFilePath(string $organizationId, string $projectId, string $eventType, int $timestampMs): string
    {
        $date = new \DateTimeImmutable('@'.(int) ($timestampMs / 1000));
        $batchId = Uuid::v7();

        // For protocol-based paths (aws-s3://, memory://), keep as-is
        // For local paths, ensure trailing slash
        if (str_contains($this->basePath, '://')) {
            $base = $this->basePath;
        } else {
            $base = rtrim($this->basePath, '/').'/';
        }

        return sprintf(
            '%sorganization_id=%s/project_id=%s/event_type=%s/dt=%s/events_%s_%s.parquet',
            $base,
            $organizationId,
            $projectId,
            $eventType,
            $date->format('Y-m-d'),
            $date->format('His'),
            $batchId
        );
    }

    /**
     * Get the storage directory for a project within an organization.
     */
    public function getProjectStoragePath(string $organizationId, string $projectId): string
    {
        // For protocol-based paths (aws-s3://, memory://), keep as-is
        // For local paths, ensure trailing slash
        if (str_contains($this->basePath, '://')) {
            $base = $this->basePath;
        } else {
            $base = rtrim($this->basePath, '/').'/';
        }

        return sprintf(
            '%sorganization_id=%s/project_id=%s',
            $base,
            $organizationId,
            $projectId
        );
    }

    /**
     * Convert an event array to a row array for Parquet.
     *
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    private function eventToRow(array $event): array
    {
        // Ensure JSON fields are encoded
        if (isset($event['tags']) && is_array($event['tags'])) {
            $event['tags'] = json_encode($event['tags']);
        }
        if (isset($event['context']) && is_array($event['context'])) {
            $event['context'] = json_encode($event['context']);
        }
        if (isset($event['breadcrumbs']) && is_array($event['breadcrumbs'])) {
            $event['breadcrumbs'] = json_encode($event['breadcrumbs']);
        }
        if (isset($event['stack_trace']) && is_array($event['stack_trace'])) {
            $event['stack_trace'] = json_encode($event['stack_trace']);
        }

        return $event;
    }

    /**
     * Ensure the directory exists (only needed for local filesystem).
     */
    private function ensureDirectoryExists(string $path): void
    {
        // S3 and memory storage don't need directories to be created
        if ($this->storageFactory->requiresStreamOperations()) {
            return;
        }

        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true) && !is_dir($path)) {
                throw new \RuntimeException("Failed to create directory: {$path}");
            }
        }
    }

    /**
     * Get the buffer size.
     */
    public function getBufferSize(): int
    {
        return count($this->buffer);
    }

    /**
     * Clear the buffer without writing.
     */
    public function clearBuffer(): void
    {
        $this->buffer = [];
    }

    private function startSpan(string $name): SpanInterface
    {
        return Globals::tracerProvider()->getTracer('errata')
            ->spanBuilder($name)
            ->startSpan();
    }
}
