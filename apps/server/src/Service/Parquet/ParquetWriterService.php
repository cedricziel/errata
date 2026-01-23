<?php

declare(strict_types=1);

namespace App\Service\Parquet;

use App\Service\Storage\StorageFactory;
use Flow\ETL\Row;
use Flow\ETL\Row\Entry;
use Flow\ETL\Rows;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

use function Flow\ETL\Adapter\Parquet\to_parquet;
use function Flow\ETL\DSL\bool_entry;
use function Flow\ETL\DSL\data_frame;
use function Flow\ETL\DSL\float_entry;
use function Flow\ETL\DSL\from_rows;
use function Flow\ETL\DSL\int_entry;
use function Flow\ETL\DSL\null_entry;
use function Flow\ETL\DSL\str_entry;

/**
 * Service for writing wide events to Parquet files using Flow-PHP DataFrame API.
 *
 * Supports both local filesystem and S3-compatible storage.
 */
class ParquetWriterService
{
    private const BATCH_SIZE = 1000;

    /** @var array<string, array<array<string, mixed>>> Partition-keyed buffers */
    private array $partitionBuffers = [];

    public function __construct(
        private readonly StorageFactory $storageFactory,
        private readonly FlowConfigFactory $flowConfigFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Add an event to the write buffer (partition-aware).
     *
     * Events are buffered by partition key and flushed when the partition buffer reaches BATCH_SIZE.
     *
     * @param array<string, mixed> $event
     */
    public function addEvent(array $event): void
    {
        $key = $this->getPartitionKey($event);
        $this->partitionBuffers[$key][] = WideEventSchema::normalize($event);

        if (count($this->partitionBuffers[$key]) >= self::BATCH_SIZE) {
            $this->flushPartition($key);
        }
    }

    /**
     * Add multiple events to the write buffer (partition-aware).
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
     * Flush all partition buffers to Parquet files.
     */
    public function flush(): void
    {
        foreach (array_keys($this->partitionBuffers) as $key) {
            $this->flushPartition($key);
        }
    }

    /**
     * Flush a specific partition buffer to a Parquet file.
     */
    private function flushPartition(string $partitionKey): void
    {
        if (empty($this->partitionBuffers[$partitionKey])) {
            return;
        }

        try {
            $this->writeEvents($this->partitionBuffers[$partitionKey]);
            unset($this->partitionBuffers[$partitionKey]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to flush partition to Parquet', [
                'error' => $e->getMessage(),
                'partition' => $partitionKey,
                'event_count' => count($this->partitionBuffers[$partitionKey]),
            ]);
            throw $e;
        }
    }

    /**
     * Compute partition key for an event.
     *
     * @param array<string, mixed> $event
     */
    private function getPartitionKey(array $event): string
    {
        $orgId = $event['organization_id'] ?? 'unknown';
        $projectId = $event['project_id'] ?? 'unknown';
        $eventType = $event['event_type'] ?? 'unknown';
        $timestamp = $event['timestamp'] ?? (int) (microtime(true) * 1000);
        $date = date('Y-m-d', (int) ($timestamp / 1000));

        return "{$orgId}/{$projectId}/{$eventType}/{$date}";
    }

    /**
     * Write events directly to a Parquet file using DataFrame API.
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

            // Ensure directory exists for local filesystem
            if (!$this->storageFactory->requiresStreamOperations()) {
                $this->ensureDirectoryExists(dirname($filePath));
            }

            $span->setAttribute('file.path', $filePath);

            // Convert events to Flow Rows
            $rows = [];
            foreach ($events as $event) {
                $normalized = WideEventSchema::normalize($event);
                $rows[] = $this->eventToRow($normalized);
            }

            // Get config with proper filesystem mounting (S3 or local)
            $config = $this->flowConfigFactory->createConfig();

            // Write using DataFrame API
            data_frame($config)
                ->read(from_rows(new Rows(...$rows)))
                ->write(to_parquet($filePath))
                ->run();

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

        $basePath = $this->storageFactory->getBasePath();

        // For protocol-based paths (aws-s3://, memory://), keep as-is
        // For local paths, ensure trailing slash
        if (str_contains($basePath, '://')) {
            $base = $basePath;
        } else {
            $base = rtrim($basePath, '/').'/';
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
        $basePath = $this->storageFactory->getBasePath();

        // For protocol-based paths (aws-s3://, memory://), keep as-is
        // For local paths, ensure trailing slash
        if (str_contains($basePath, '://')) {
            $base = $basePath;
        } else {
            $base = rtrim($basePath, '/').'/';
        }

        return sprintf(
            '%sorganization_id=%s/project_id=%s',
            $base,
            $organizationId,
            $projectId
        );
    }

    /**
     * Convert an event array to a Flow Row.
     *
     * @param array<string, mixed> $event
     */
    private function eventToRow(array $event): Row
    {
        $entries = [];

        foreach ($event as $key => $value) {
            // Encode JSON fields
            if (in_array($key, ['tags', 'context', 'breadcrumbs', 'stack_trace'], true) && is_array($value)) {
                $value = json_encode($value);
            }

            $entries[] = $this->createEntry($key, $value);
        }

        return Row::create(...$entries);
    }

    /**
     * Create an Entry from a key-value pair with appropriate type.
     */
    private function createEntry(string $key, mixed $value): Entry
    {
        if (null === $value) {
            return null_entry($key);
        }

        if (is_int($value)) {
            return int_entry($key, $value);
        }

        if (is_float($value)) {
            return float_entry($key, $value);
        }

        if (is_bool($value)) {
            return bool_entry($key, $value);
        }

        if (is_string($value)) {
            return str_entry($key, $value);
        }

        if (is_array($value)) {
            return str_entry($key, json_encode($value) ?: '[]');
        }

        return str_entry($key, (string) $value);
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
     * Get the total buffer size across all partitions.
     */
    public function getBufferSize(): int
    {
        $total = 0;
        foreach ($this->partitionBuffers as $buffer) {
            $total += count($buffer);
        }

        return $total;
    }

    /**
     * Clear all partition buffers without writing.
     */
    public function clearBuffer(): void
    {
        $this->partitionBuffers = [];
    }

    private function startSpan(string $name): SpanInterface
    {
        return Globals::tracerProvider()->getTracer('errata')
            ->spanBuilder($name)
            ->startSpan();
    }
}
