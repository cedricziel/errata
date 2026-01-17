<?php

declare(strict_types=1);

namespace App\Service\Otel;

use App\Service\Parquet\ParquetReaderService;
use App\Service\Parquet\WideEventSchema;

/**
 * Service for querying OpenTelemetry data (traces, logs, metrics) from Parquet storage.
 */
class OtelDataService
{
    public function __construct(
        private readonly ParquetReaderService $parquetReader,
    ) {
    }

    /**
     * Get traces grouped by trace_id.
     *
     * @return array<array{trace_id: string, root_operation: string|null, span_count: int, duration_ms: float, timestamp: int, has_errors: bool}>
     */
    public function getTraces(
        string $projectId,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $spans = [];
        foreach ($this->parquetReader->readEvents($projectId, $from, $to, ['event_type' => WideEventSchema::EVENT_TYPE_SPAN]) as $span) {
            $spans[] = $span;
        }

        // Group spans by trace_id
        $traces = [];
        foreach ($spans as $span) {
            $traceId = $span['trace_id'] ?? null;
            if (null === $traceId) {
                continue;
            }

            if (!isset($traces[$traceId])) {
                $traces[$traceId] = [
                    'trace_id' => $traceId,
                    'spans' => [],
                    'timestamp' => $span['timestamp'] ?? 0,
                ];
            }

            $traces[$traceId]['spans'][] = $span;
            // Track earliest timestamp for the trace
            if (($span['timestamp'] ?? 0) < $traces[$traceId]['timestamp']) {
                $traces[$traceId]['timestamp'] = $span['timestamp'] ?? 0;
            }
        }

        // Calculate trace metadata
        $result = [];
        foreach ($traces as $traceId => $trace) {
            $rootSpan = $this->findRootSpan($trace['spans']);
            $hasErrors = false;
            $maxEndTime = 0;
            $minStartTime = PHP_INT_MAX;

            foreach ($trace['spans'] as $span) {
                if ('error' === ($span['span_status'] ?? null) || 'ERROR' === ($span['span_status'] ?? null)) {
                    $hasErrors = true;
                }
                $startTime = $span['timestamp'] ?? 0;
                $duration = $span['duration_ms'] ?? 0;
                $endTime = $startTime + ($duration * 1000); // Convert ms to microseconds if needed

                if ($startTime < $minStartTime) {
                    $minStartTime = $startTime;
                }
                if ($endTime > $maxEndTime) {
                    $maxEndTime = $endTime;
                }
            }

            $result[] = [
                'trace_id' => $traceId,
                'root_operation' => $rootSpan['operation'] ?? null,
                'span_count' => count($trace['spans']),
                'duration_ms' => $rootSpan['duration_ms'] ?? ($maxEndTime > $minStartTime ? ($maxEndTime - $minStartTime) / 1000 : 0),
                'timestamp' => $trace['timestamp'],
                'has_errors' => $hasErrors,
            ];
        }

        // Sort by timestamp descending
        usort($result, fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        // Apply pagination
        return array_slice($result, $offset, $limit);
    }

    /**
     * Get all spans for a specific trace.
     *
     * @return array<array<string, mixed>>
     */
    public function getTraceSpans(string $projectId, string $traceId): array
    {
        $spans = [];
        foreach ($this->parquetReader->readEvents($projectId, null, null, ['event_type' => WideEventSchema::EVENT_TYPE_SPAN]) as $span) {
            if (($span['trace_id'] ?? null) === $traceId) {
                $spans[] = $span;
            }
        }

        // Sort by timestamp
        usort($spans, fn ($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));

        return $spans;
    }

    /**
     * Get logs with optional filters.
     *
     * @return array<array<string, mixed>>
     */
    public function getLogs(
        string $projectId,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?string $severity = null,
        ?string $search = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $logs = [];
        $filters = ['event_type' => WideEventSchema::EVENT_TYPE_LOG];

        foreach ($this->parquetReader->readEvents($projectId, $from, $to, $filters) as $log) {
            // Apply severity filter
            if (null !== $severity && '' !== $severity) {
                if (($log['severity'] ?? null) !== $severity) {
                    continue;
                }
            }

            // Apply search filter
            if (null !== $search && '' !== $search) {
                $message = $log['message'] ?? '';
                if (false === stripos($message, $search)) {
                    continue;
                }
            }

            $logs[] = $log;
        }

        // Sort by timestamp descending
        usort($logs, fn ($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

        // Apply pagination
        return array_slice($logs, $offset, $limit);
    }

    /**
     * Get a single log by event ID.
     *
     * @return array<string, mixed>|null
     */
    public function getLog(string $projectId, string $eventId): ?array
    {
        foreach ($this->parquetReader->readEvents($projectId, null, null, ['event_type' => WideEventSchema::EVENT_TYPE_LOG]) as $log) {
            if (($log['event_id'] ?? null) === $eventId) {
                return $log;
            }
        }

        return null;
    }

    /**
     * Get metrics with optional filters.
     *
     * @return array<array<string, mixed>>
     */
    public function getMetrics(
        string $projectId,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?string $metricName = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $metrics = [];

        foreach ($this->parquetReader->readEvents($projectId, $from, $to, ['event_type' => WideEventSchema::EVENT_TYPE_METRIC]) as $metric) {
            // Apply metric name filter
            if (null !== $metricName && '' !== $metricName) {
                if (($metric['metric_name'] ?? null) !== $metricName) {
                    continue;
                }
            }

            $metrics[] = $metric;
        }

        // Sort by timestamp descending
        usort($metrics, fn ($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

        // Apply pagination
        return array_slice($metrics, $offset, $limit);
    }

    /**
     * Get unique metric names for a project.
     *
     * @return array<string>
     */
    public function getMetricNames(string $projectId): array
    {
        $names = [];

        foreach ($this->parquetReader->readEvents($projectId, null, null, ['event_type' => WideEventSchema::EVENT_TYPE_METRIC]) as $metric) {
            $name = $metric['metric_name'] ?? null;
            if (null !== $name && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Build a span tree from flat spans.
     *
     * @param array<array<string, mixed>> $spans
     *
     * @return array<array<string, mixed>>
     */
    public function buildSpanTree(array $spans): array
    {
        $spansBySpanId = [];
        foreach ($spans as $span) {
            $spanId = $span['span_id'] ?? null;
            if (null !== $spanId) {
                $span['children'] = [];
                $spansBySpanId[$spanId] = $span;
            }
        }

        $rootSpans = [];
        foreach ($spansBySpanId as $spanId => $span) {
            $parentId = $span['parent_span_id'] ?? null;
            if (null === $parentId || '' === $parentId || !isset($spansBySpanId[$parentId])) {
                $rootSpans[] = &$spansBySpanId[$spanId];
            } else {
                $spansBySpanId[$parentId]['children'][] = &$spansBySpanId[$spanId];
            }
        }

        return $rootSpans;
    }

    /**
     * Find the root span (span without parent) in a list of spans.
     *
     * @param array<array<string, mixed>> $spans
     *
     * @return array<string, mixed>|null
     */
    private function findRootSpan(array $spans): ?array
    {
        foreach ($spans as $span) {
            $parentId = $span['parent_span_id'] ?? null;
            if (null === $parentId || '' === $parentId) {
                return $span;
            }
        }

        // If no root found, return first span
        return $spans[0] ?? null;
    }
}
