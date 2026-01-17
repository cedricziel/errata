<?php

declare(strict_types=1);

namespace App\Service\Parquet;

use App\Service\Telemetry\TracerFactory;
use Flow\Parquet\Reader;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/**
 * Service for reading wide events from Parquet files.
 */
class ParquetReaderService
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/storage/parquet')]
        private readonly string $storagePath,
        private readonly LoggerInterface $logger,
        private readonly TracerFactory $tracerFactory,
    ) {
    }

    /**
     * Read events with Hive-style partition filtering.
     *
     * @param array<\App\DTO\QueryBuilder\QueryFilter> $filters Additional filters to apply to event data
     *
     * @return \Generator<array<string, mixed>>
     */
    public function readEvents(
        ?string $organizationId = null,
        ?string $projectId = null,
        ?string $eventType = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        array $filters = [],
    ): \Generator {
        $span = $this->startSpan('parquet.read_events');
        $span?->setAttribute('organization.id', $organizationId ?? 'all');
        $span?->setAttribute('project.id', $projectId ?? 'all');
        $span?->setAttribute('event.type', $eventType ?? 'all');
        $span?->setAttribute('filter.count', count($filters));

        try {
            $files = $this->findParquetFiles($organizationId, $projectId, $eventType, $from, $to);
            $span?->setAttribute('file.count', count($files));

            foreach ($files as $file) {
                yield from $this->readFile($file, $filters);
            }

            $span?->setStatus(StatusCode::STATUS_OK);
        } catch (\Throwable $e) {
            $span?->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span?->recordException($e);

            throw $e;
        } finally {
            $span?->end();
        }
    }

    /**
     * Read events from a specific Parquet file.
     *
     * @param array<\App\DTO\QueryBuilder\QueryFilter> $filters
     *
     * @return \Generator<array<string, mixed>>
     */
    public function readFile(string $filePath, array $filters = []): \Generator
    {
        if (!file_exists($filePath)) {
            $this->logger->warning('Parquet file not found', ['file' => $filePath]);

            return;
        }

        try {
            $reader = new Reader();
            $parquetFile = $reader->read($filePath);

            foreach ($parquetFile->values() as $row) {
                $event = $this->rowToEvent($row);

                if ($this->matchesFilters($event, $filters)) {
                    yield $event;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to read Parquet file', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Read events from multiple Parquet files with column pruning.
     *
     * This method is optimized for single-pass processing:
     * - Only reads the specified columns from Parquet files
     * - Applies filters during iteration
     * - Returns a generator for memory efficiency
     *
     * @param array<string>                            $files   List of Parquet file paths
     * @param array<string>                            $columns Columns to read (for pruning)
     * @param array<\App\DTO\QueryBuilder\QueryFilter> $filters Filters to apply
     *
     * @return \Generator<array<string, mixed>>
     */
    public function readEventsWithColumns(array $files, array $columns, array $filters = []): \Generator
    {
        foreach ($files as $filePath) {
            yield from $this->readFileWithColumns($filePath, $columns, $filters);
        }
    }

    /**
     * Read events from a specific Parquet file with column pruning.
     *
     * @param array<string>                            $columns Columns to read
     * @param array<\App\DTO\QueryBuilder\QueryFilter> $filters Filters to apply
     *
     * @return \Generator<array<string, mixed>>
     */
    public function readFileWithColumns(string $filePath, array $columns, array $filters = []): \Generator
    {
        if (!file_exists($filePath)) {
            $this->logger->warning('Parquet file not found', ['file' => $filePath]);

            return;
        }

        try {
            $reader = new Reader();
            $parquetFile = $reader->read($filePath);

            // Use column pruning if columns are specified
            $valueIterator = !empty($columns)
                ? $parquetFile->values($columns)
                : $parquetFile->values();

            foreach ($valueIterator as $row) {
                $event = $this->rowToEvent($row);

                if ($this->matchesFilters($event, $filters)) {
                    yield $event;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to read Parquet file', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Count events with Hive-style partition filtering.
     *
     * @param array<\App\DTO\QueryBuilder\QueryFilter> $filters
     */
    public function countEvents(
        ?string $organizationId = null,
        ?string $projectId = null,
        ?string $eventType = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        array $filters = [],
    ): int {
        $count = 0;

        foreach ($this->readEvents($organizationId, $projectId, $eventType, $from, $to, $filters) as $event) {
            ++$count;
        }

        return $count;
    }

    /**
     * Get event statistics with Hive-style partition filtering.
     *
     * @return array<string, mixed>
     */
    public function getEventStats(
        ?string $organizationId = null,
        ?string $projectId = null,
        ?string $eventType = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        $stats = [
            'total' => 0,
            'by_type' => [],
            'by_severity' => [],
            'by_day' => [],
        ];

        foreach ($this->readEvents($organizationId, $projectId, $eventType, $from, $to) as $event) {
            ++$stats['total'];

            // Count by type
            $type = $event['event_type'] ?? 'unknown';
            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;

            // Count by severity
            if (!empty($event['severity'])) {
                $severity = $event['severity'];
                $stats['by_severity'][$severity] = ($stats['by_severity'][$severity] ?? 0) + 1;
            }

            // Count by day
            if (!empty($event['timestamp'])) {
                $date = date('Y-m-d', (int) ($event['timestamp'] / 1000));
                $stats['by_day'][$date] = ($stats['by_day'][$date] ?? 0) + 1;
            }
        }

        ksort($stats['by_day']);

        return $stats;
    }

    /**
     * Get events for a specific issue (by fingerprint).
     *
     * @return array<array<string, mixed>>
     */
    public function getEventsByFingerprint(
        string $fingerprint,
        ?string $organizationId = null,
        ?string $projectId = null,
        ?string $eventType = null,
        int $limit = 50,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        $span = $this->startSpan('parquet.get_events_by_fingerprint');
        $span?->setAttribute('fingerprint', $fingerprint);
        $span?->setAttribute('organization.id', $organizationId ?? 'all');
        $span?->setAttribute('project.id', $projectId ?? 'all');
        $span?->setAttribute('limit', $limit);

        try {
            $events = [];
            $filters = [
                new \App\DTO\QueryBuilder\QueryFilter('fingerprint', \App\DTO\QueryBuilder\QueryFilter::OPERATOR_EQ, $fingerprint),
            ];

            foreach ($this->readEvents($organizationId, $projectId, $eventType, $from, $to, $filters) as $event) {
                $events[] = $event;

                if (count($events) >= $limit) {
                    break;
                }
            }

            // Sort by timestamp descending
            usort($events, fn ($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

            $span?->setAttribute('event.count', count($events));
            $span?->setStatus(StatusCode::STATUS_OK);

            return $events;
        } catch (\Throwable $e) {
            $span?->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span?->recordException($e);

            throw $e;
        } finally {
            $span?->end();
        }
    }

    /**
     * Find Parquet files with Hive-style partition filtering.
     *
     * @return array<string>
     */
    public function findParquetFiles(
        ?string $organizationId = null,
        ?string $projectId = null,
        ?string $eventType = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        // Build the base path with partition pruning
        $basePath = $this->storagePath;

        if (null !== $organizationId) {
            $basePath .= '/organization_id='.$organizationId;
        }

        if (null !== $projectId) {
            if (null === $organizationId) {
                // If no org specified but project is, we need to search all orgs
                $basePath = $this->storagePath;
            } else {
                $basePath .= '/project_id='.$projectId;
            }
        }

        if (null !== $eventType && null !== $organizationId && null !== $projectId) {
            $basePath .= '/event_type='.$eventType;
        }

        if (!is_dir($basePath)) {
            // Try legacy path format for backward compatibility
            return $this->findLegacyParquetFiles($projectId, $from, $to);
        }

        $finder = new Finder();
        $finder->files()
            ->in($basePath)
            ->name('*.parquet')
            ->sortByName();

        // Filter by partition values
        $finder->filter(function (\SplFileInfo $file) use ($organizationId, $projectId, $eventType, $from, $to) {
            $path = $file->getPathname();
            $partitions = $this->extractPartitionValues($path);

            // Filter by organization
            if (null !== $organizationId && ($partitions['organization_id'] ?? null) !== $organizationId) {
                return false;
            }

            // Filter by project
            if (null !== $projectId && ($partitions['project_id'] ?? null) !== $projectId) {
                return false;
            }

            // Filter by event type
            if (null !== $eventType && ($partitions['event_type'] ?? null) !== $eventType) {
                return false;
            }

            // Filter by date range
            if ((null !== $from || null !== $to) && isset($partitions['dt'])) {
                return $this->dateMatchesRange($partitions['dt'], $from, $to);
            }

            return true;
        });

        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }

    /**
     * Extract partition key-value pairs from a Hive-style path.
     *
     * @return array<string, string>
     */
    public function extractPartitionValues(string $path): array
    {
        preg_match_all('/(\w+)=([^\/]+)/', $path, $matches, PREG_SET_ORDER);

        $values = [];
        foreach ($matches as $match) {
            $values[$match[1]] = $match[2];
        }

        return $values;
    }

    /**
     * Check if a date string matches the given range.
     */
    private function dateMatchesRange(
        string $dateStr,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to,
    ): bool {
        try {
            $fileDate = new \DateTimeImmutable($dateStr);
        } catch (\Exception) {
            return true; // If we can't parse, include the file
        }

        if (null !== $from) {
            $fromDate = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0, 0);
            if ($fileDate < $fromDate) {
                return false;
            }
        }

        if (null !== $to) {
            $toDate = \DateTimeImmutable::createFromInterface($to)->setTime(23, 59, 59);
            if ($fileDate > $toDate) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find Parquet files using legacy path format (backward compatibility).
     *
     * @return array<string>
     */
    private function findLegacyParquetFiles(
        ?string $projectId,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        if (null === $projectId) {
            return [];
        }

        $basePath = $this->storagePath.'/'.$projectId;

        if (!is_dir($basePath)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()
            ->in($basePath)
            ->name('*.parquet')
            ->sortByName();

        // Filter by date if specified (legacy path format: /project_id/YYYY/MM/DD/)
        if (null !== $from || null !== $to) {
            $finder->filter(function (\SplFileInfo $file) use ($from, $to) {
                return $this->legacyFileMatchesDateRange($file->getPathname(), $from, $to);
            });
        }

        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }

    /**
     * Check if a file's legacy path matches the date range.
     */
    private function legacyFileMatchesDateRange(
        string $filePath,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to,
    ): bool {
        // Extract date from path like: /project_id/2024/01/15/events_*.parquet
        if (preg_match('#/(\d{4})/(\d{2})/(\d{2})/#', $filePath, $matches)) {
            $fileDate = new \DateTimeImmutable("{$matches[1]}-{$matches[2]}-{$matches[3]}");

            if (null !== $from) {
                $fromDate = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0, 0);
                if ($fileDate < $fromDate) {
                    return false;
                }
            }

            if (null !== $to) {
                $toDate = \DateTimeImmutable::createFromInterface($to)->setTime(23, 59, 59);
                if ($fileDate > $toDate) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Convert a Parquet row to an event array.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function rowToEvent(array $row): array
    {
        $event = $row;

        // Decode JSON fields
        if (isset($event['tags']) && is_string($event['tags'])) {
            $event['tags'] = json_decode($event['tags'], true) ?? [];
        }
        if (isset($event['context']) && is_string($event['context'])) {
            $event['context'] = json_decode($event['context'], true) ?? [];
        }
        if (isset($event['breadcrumbs']) && is_string($event['breadcrumbs'])) {
            $event['breadcrumbs'] = json_decode($event['breadcrumbs'], true) ?? [];
        }
        if (isset($event['stack_trace']) && is_string($event['stack_trace'])) {
            $event['stack_trace'] = json_decode($event['stack_trace'], true) ?? [];
        }

        return $event;
    }

    /**
     * Check if an event matches the given filters.
     *
     * @param array<string, mixed>                     $event
     * @param array<\App\DTO\QueryBuilder\QueryFilter> $filters
     */
    private function matchesFilters(array $event, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (!$this->matchesSingleFilter($event, $filter)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if an event matches a single QueryFilter.
     *
     * @param array<string, mixed> $event
     */
    private function matchesSingleFilter(array $event, \App\DTO\QueryBuilder\QueryFilter $filter): bool
    {
        $attribute = $filter->attribute;
        $operator = $filter->operator;
        $value = $filter->value;

        // If the attribute doesn't exist in the event, it doesn't match
        // (except for 'neq' where non-existent is considered "not equal")
        if (!isset($event[$attribute]) && \App\DTO\QueryBuilder\QueryFilter::OPERATOR_NEQ !== $operator) {
            return false;
        }

        $eventValue = $event[$attribute] ?? null;

        return match ($operator) {
            \App\DTO\QueryBuilder\QueryFilter::OPERATOR_EQ => $eventValue === $value || (string) $eventValue === (string) $value,
            \App\DTO\QueryBuilder\QueryFilter::OPERATOR_NEQ => $eventValue !== $value && (string) $eventValue !== (string) $value,
            \App\DTO\QueryBuilder\QueryFilter::OPERATOR_CONTAINS => is_string($eventValue) && str_contains(strtolower($eventValue), strtolower((string) $value)),
            \App\DTO\QueryBuilder\QueryFilter::OPERATOR_STARTS_WITH => is_string($eventValue) && str_starts_with(strtolower($eventValue), strtolower((string) $value)),
            \App\DTO\QueryBuilder\QueryFilter::OPERATOR_GT => is_numeric($eventValue) && is_numeric($value) && $eventValue > $value,
            \App\DTO\QueryBuilder\QueryFilter::OPERATOR_GTE => is_numeric($eventValue) && is_numeric($value) && $eventValue >= $value,
            \App\DTO\QueryBuilder\QueryFilter::OPERATOR_LT => is_numeric($eventValue) && is_numeric($value) && $eventValue < $value,
            \App\DTO\QueryBuilder\QueryFilter::OPERATOR_LTE => is_numeric($eventValue) && is_numeric($value) && $eventValue <= $value,
            \App\DTO\QueryBuilder\QueryFilter::OPERATOR_IN => is_array($value) && in_array($eventValue, $value, false),
            default => $eventValue === $value,
        };
    }

    private function startSpan(string $name): ?SpanInterface
    {
        if (!$this->tracerFactory->isEnabled()) {
            return null;
        }

        return $this->tracerFactory->createTracer('parquet')
            ->spanBuilder($name)
            ->startSpan();
    }
}
