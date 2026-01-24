<?php

declare(strict_types=1);

namespace App\Service\Parquet;

use App\DTO\QueryBuilder\QueryFilter;
use App\Service\Storage\StorageFactory;
use Flow\ETL\Function\ScalarFunction;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Log\LoggerInterface;

use function Flow\ETL\Adapter\Parquet\from_parquet;
use function Flow\ETL\DSL\all;
use function Flow\ETL\DSL\data_frame;
use function Flow\ETL\DSL\from_all;
use function Flow\ETL\DSL\lit;
use function Flow\ETL\DSL\ref;

/**
 * Service for reading wide events from Parquet files using Flow-PHP DataFrame API.
 *
 * Supports both local filesystem and S3-compatible storage with partition pruning.
 */
class ParquetReaderService
{
    public function __construct(
        private readonly StorageFactory $storageFactory,
        private readonly FlowConfigFactory $flowConfigFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Read events with Hive-style partition filtering using DataFrame API.
     *
     * @param array<QueryFilter> $filters Additional filters to apply to event data
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
        int $limit = 0,
    ): \Generator {
        $span = $this->startSpan('parquet.read_events');
        $span->setAttribute('organization.id', $organizationId ?? 'all');
        $span->setAttribute('project.id', $projectId ?? 'all');
        $span->setAttribute('event.type', $eventType ?? 'all');
        $span->setAttribute('filter.count', count($filters));
        $span->setAttribute('storage.type', $this->storageFactory->getStorageType());

        try {
            $config = $this->flowConfigFactory->createConfig();

            // Use date-specific glob patterns for partition pruning
            // Instead of dt=* (which scans all partitions), enumerate each date
            $globs = $this->flowConfigFactory->buildGlobPatternsForDateRange(
                organizationId: $organizationId,
                projectId: $projectId,
                eventType: $eventType,
                from: $from,
                to: $to,
            );

            $this->logger->debug('Reading events with glob patterns', [
                'pattern_count' => count($globs),
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'event_type' => $eventType,
                'from' => $from?->format('Y-m-d'),
                'to' => $to?->format('Y-m-d'),
            ]);

            $extractors = array_map(
                fn (string $glob) => from_parquet($glob),
                $globs
            );

            $df = data_frame($config)->read(from_all(...$extractors));

            // Apply partition filters (these are applied during file selection)
            $partitionFilter = $this->buildPartitionFilter($organizationId, $projectId, $eventType, $from, $to);
            if (null !== $partitionFilter) {
                $df = $df->filter($partitionFilter);
            }

            // Apply row-level filters from QueryFilter array
            foreach ($filters as $filter) {
                $expression = $this->toFlowExpression($filter);
                $df = $df->filter($expression);
            }

            // Apply limit if specified
            if ($limit > 0) {
                $df = $df->limit($limit);
            }

            $eventCount = 0;
            foreach ($df->fetch() as $row) {
                $event = $this->rowToEvent($row->toArray());
                yield $event;
                ++$eventCount;
            }

            $span->setAttribute('event.count', $eventCount);
            $span->setStatus(StatusCode::STATUS_OK);
        } catch (\Throwable $e) {
            // Log but don't fail if no files match the glob pattern
            if (str_contains($e->getMessage(), 'No files found') || str_contains($e->getMessage(), 'does not exist')) {
                $this->logger->debug('No parquet files found for glob pattern', [
                    'error' => $e->getMessage(),
                ]);
                $span->setStatus(StatusCode::STATUS_OK);

                return;
            }

            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->recordException($e);

            throw $e;
        } finally {
            $span->end();
        }
    }

    /**
     * Read events with specific column selection for efficiency.
     *
     * @param array<string>      $columns Columns to read (column pruning)
     * @param array<QueryFilter> $filters Filters to apply
     *
     * @return \Generator<array<string, mixed>>
     */
    public function readEventsWithColumns(
        ?string $organizationId,
        ?string $projectId,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to,
        array $columns,
        array $filters = [],
    ): \Generator {
        $span = $this->startSpan('parquet.read_events_with_columns');
        $span->setAttribute('column.count', count($columns));

        try {
            $config = $this->flowConfigFactory->createConfig();

            // Ensure partition columns are included for filtering, even if not requested
            $columnsToRead = $columns;
            if (null !== $organizationId && !in_array('organization_id', $columnsToRead, true)) {
                $columnsToRead[] = 'organization_id';
            }
            if (null !== $projectId && !in_array('project_id', $columnsToRead, true)) {
                $columnsToRead[] = 'project_id';
            }
            if ((null !== $from || null !== $to) && !in_array('timestamp', $columnsToRead, true)) {
                $columnsToRead[] = 'timestamp';
            }

            // Use date-specific glob patterns for partition pruning
            // Instead of dt=* (which scans all partitions), enumerate each date
            $globs = $this->flowConfigFactory->buildGlobPatternsForDateRange(
                organizationId: $organizationId,
                projectId: $projectId,
                from: $from,
                to: $to,
            );

            $this->logger->debug('Reading events with columns using glob patterns', [
                'pattern_count' => count($globs),
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'from' => $from?->format('Y-m-d'),
                'to' => $to?->format('Y-m-d'),
            ]);

            $extractors = array_map(
                fn (string $glob) => from_parquet($glob, columns: $columnsToRead),
                $globs
            );

            $df = data_frame($config)->read(from_all(...$extractors));

            // Apply partition filters to narrow down results
            $partitionFilter = $this->buildPartitionFilter($organizationId, $projectId, null, $from, $to);
            if (null !== $partitionFilter) {
                $df = $df->filter($partitionFilter);
            }

            // Apply row-level filters
            foreach ($filters as $filter) {
                $expression = $this->toFlowExpression($filter);
                $df = $df->filter($expression);
            }

            $eventCount = 0;
            foreach ($df->fetch() as $row) {
                $event = $this->rowToEvent($row->toArray());
                yield $event;
                ++$eventCount;
            }

            $span->setAttribute('event.count', $eventCount);
            $span->setStatus(StatusCode::STATUS_OK);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'No files found') || str_contains($e->getMessage(), 'does not exist')) {
                $this->logger->debug('No parquet files found', ['error' => $e->getMessage()]);
                $span->setStatus(StatusCode::STATUS_OK);

                return;
            }

            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->recordException($e);

            throw $e;
        } finally {
            $span->end();
        }
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
        $startTime = microtime(true);
        $span = $this->startSpan('parquet.get_events_by_fingerprint');
        $span->setAttribute('fingerprint', $fingerprint);
        $span->setAttribute('organization.id', $organizationId ?? 'all');
        $span->setAttribute('project.id', $projectId ?? 'all');
        $span->setAttribute('limit', $limit);
        $span->setAttribute('storage.type', $this->storageFactory->getStorageType());
        $span->setAttribute('from', $from?->format('Y-m-d H:i:s') ?? 'null');
        $span->setAttribute('to', $to?->format('Y-m-d H:i:s') ?? 'null');

        $this->logger->info('getEventsByFingerprint started', [
            'fingerprint' => substr($fingerprint, 0, 16).'...',
            'from' => $from?->format('Y-m-d'),
            'to' => $to?->format('Y-m-d'),
        ]);

        try {
            $filters = [
                new QueryFilter('fingerprint', QueryFilter::OPERATOR_EQ, $fingerprint),
            ];

            $events = [];
            foreach ($this->readEvents($organizationId, $projectId, $eventType, $from, $to, $filters, $limit) as $event) {
                $events[] = $event;
            }

            // Sort by timestamp descending
            usort($events, fn ($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

            $duration = microtime(true) - $startTime;
            $span->setAttribute('event.count', count($events));
            $span->setAttribute('duration_ms', (int) ($duration * 1000));
            $span->setStatus(StatusCode::STATUS_OK);

            $this->logger->info('getEventsByFingerprint completed', [
                'fingerprint' => substr($fingerprint, 0, 16).'...',
                'event_count' => count($events),
                'duration_ms' => (int) ($duration * 1000),
            ]);

            return $events;
        } catch (\Throwable $e) {
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->recordException($e);

            throw $e;
        } finally {
            $span->end();
        }
    }

    /**
     * Count events matching the given criteria.
     *
     * @param array<QueryFilter> $filters
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
     * Build a partition filter expression for DataFrame filtering.
     *
     * These filters are applied to partition columns (organization_id, project_id, event_type, dt).
     */
    private function buildPartitionFilter(
        ?string $organizationId,
        ?string $projectId,
        ?string $eventType,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to,
    ): ?ScalarFunction {
        $conditions = [];

        if (null !== $organizationId) {
            $conditions[] = ref('organization_id')->equals(lit($organizationId));
        }

        if (null !== $projectId) {
            $conditions[] = ref('project_id')->equals(lit($projectId));
        }

        if (null !== $eventType) {
            $conditions[] = ref('event_type')->equals(lit($eventType));
        }

        // Apply date range filter on timestamp column (milliseconds)
        if (null !== $from) {
            $fromMs = $from->getTimestamp() * 1000;
            $conditions[] = ref('timestamp')->greaterThanEqual(lit($fromMs));
        }

        if (null !== $to) {
            // Add 1 day to include the full end day
            $toDate = \DateTimeImmutable::createFromInterface($to)->setTime(23, 59, 59);
            $toMs = $toDate->getTimestamp() * 1000;
            $conditions[] = ref('timestamp')->lessThanEqual(lit($toMs));
        }

        if (empty($conditions)) {
            return null;
        }

        // Combine all conditions with AND using all()
        return all(...$conditions);
    }

    /**
     * Convert a QueryFilter to a Flow-PHP expression.
     */
    private function toFlowExpression(QueryFilter $filter): ScalarFunction
    {
        $attribute = $filter->attribute;
        $value = $filter->value;

        return match ($filter->operator) {
            QueryFilter::OPERATOR_EQ => ref($attribute)->equals(lit($value)),
            QueryFilter::OPERATOR_NEQ => ref($attribute)->notEquals(lit($value)),
            QueryFilter::OPERATOR_GT => ref($attribute)->greaterThan(lit($value)),
            QueryFilter::OPERATOR_GTE => ref($attribute)->greaterThanEqual(lit($value)),
            QueryFilter::OPERATOR_LT => ref($attribute)->lessThan(lit($value)),
            QueryFilter::OPERATOR_LTE => ref($attribute)->lessThanEqual(lit($value)),
            QueryFilter::OPERATOR_CONTAINS => ref($attribute)->contains(lit($value)),
            QueryFilter::OPERATOR_STARTS_WITH => ref($attribute)->startsWith(lit($value)),
            QueryFilter::OPERATOR_IN => is_array($value) ? ref($attribute)->isIn(lit($value)) : ref($attribute)->equals(lit($value)),
            default => ref($attribute)->equals(lit($value)),
        };
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

    private function startSpan(string $name): SpanInterface
    {
        return Globals::tracerProvider()->getTracer('errata')
            ->spanBuilder($name)
            ->startSpan();
    }
}
