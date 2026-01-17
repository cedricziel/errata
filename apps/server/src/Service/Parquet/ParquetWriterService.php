<?php

declare(strict_types=1);

namespace App\Service\Parquet;

use Flow\Parquet\Writer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Service for writing wide events to Parquet files.
 */
class ParquetWriterService
{
    private const BATCH_SIZE = 1000;

    /** @var array<string, mixed>[] */
    private array $buffer = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/storage/parquet')]
        private readonly string $storagePath,
        private readonly LoggerInterface $logger,
    ) {
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

        // Determine the path based on the first event
        $firstEvent = $events[0];
        $projectId = $firstEvent['project_id'] ?? 'unknown';
        $timestamp = $firstEvent['timestamp'] ?? (int) (microtime(true) * 1000);

        $filePath = $this->getFilePath($projectId, $timestamp);
        $this->ensureDirectoryExists(dirname($filePath));

        $writer = new Writer();
        $schema = WideEventSchema::getSchema();

        $writer->open($filePath, $schema);

        foreach ($events as $event) {
            $normalized = WideEventSchema::normalize($event);
            $writer->writeRow($this->eventToRow($normalized));
        }

        $writer->close();

        $this->logger->info('Wrote events to Parquet file', [
            'file' => $filePath,
            'event_count' => count($events),
        ]);

        return $filePath;
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
     * Get the file path for a given project and timestamp.
     */
    public function getFilePath(string $projectId, int $timestampMs): string
    {
        $date = new \DateTimeImmutable('@' . (int) ($timestampMs / 1000));
        $batchId = Uuid::v7();

        return sprintf(
            '%s/%s/%s/%s/%s/events_%s_%s.parquet',
            $this->storagePath,
            $projectId,
            $date->format('Y'),
            $date->format('m'),
            $date->format('d'),
            $date->format('His'),
            $batchId
        );
    }

    /**
     * Get the storage directory for a project.
     */
    public function getProjectStoragePath(string $projectId): string
    {
        return $this->storagePath . '/' . $projectId;
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
     * Ensure the directory exists.
     */
    private function ensureDirectoryExists(string $path): void
    {
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
}
