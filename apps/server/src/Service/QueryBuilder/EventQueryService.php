<?php

declare(strict_types=1);

namespace App\Service\QueryBuilder;

use App\DTO\QueryBuilder\QueryRequest;
use App\DTO\QueryBuilder\QueryResult;
use App\Service\Parquet\ParquetReaderService;

/**
 * Executes queries against the Parquet event storage.
 */
class EventQueryService
{
    public function __construct(
        private readonly ParquetReaderService $parquetReader,
        private readonly FacetAggregationService $facetAggregation,
    ) {
    }

    /**
     * Execute a query and return results with facets.
     */
    public function executeQuery(QueryRequest $request, ?string $organizationId = null): QueryResult
    {
        // Read events matching the base criteria
        $events = iterator_to_array($this->parquetReader->readEvents(
            organizationId: $organizationId,
            projectId: $request->projectId,
            eventType: null, // We'll filter by event_type in the advanced filters
            from: $request->startDate,
            to: $request->endDate,
            filters: $request->filters,
        ));

        // Get total count before pagination
        $total = count($events);

        // Compute facets from the filtered events
        $facets = $this->facetAggregation->computeFacets($events, $request->filters);

        // Apply grouping if specified
        $groupedResults = [];
        if (null !== $request->groupBy) {
            $groupedResults = $this->groupEvents($events, $request->groupBy);
            // When grouping, we don't return individual events
            $events = [];
        } else {
            // Sort by timestamp descending (newest first)
            usort($events, function (array $a, array $b) {
                $tsA = $a['timestamp'] ?? 0;
                $tsB = $b['timestamp'] ?? 0;

                return $tsB <=> $tsA;
            });

            // Apply pagination
            $events = array_slice($events, $request->getOffset(), $request->limit);
        }

        return new QueryResult(
            events: $events,
            total: $total,
            facets: $facets,
            groupedResults: $groupedResults,
            page: $request->page,
            limit: $request->limit,
        );
    }

    /**
     * Execute a query and return only events without facets (faster for export).
     *
     * @return array<array<string, mixed>>
     */
    public function executeQueryForExport(QueryRequest $request, ?string $organizationId = null): array
    {
        $events = iterator_to_array($this->parquetReader->readEvents(
            organizationId: $organizationId,
            projectId: $request->projectId,
            eventType: null,
            from: $request->startDate,
            to: $request->endDate,
            filters: $request->filters,
        ));

        // Sort by timestamp descending
        usort($events, function (array $a, array $b) {
            $tsA = $a['timestamp'] ?? 0;
            $tsB = $b['timestamp'] ?? 0;

            return $tsB <=> $tsA;
        });

        return $events;
    }

    /**
     * Group events by a specific attribute.
     *
     * @param array<array<string, mixed>> $events
     *
     * @return array<array<string, mixed>>
     */
    private function groupEvents(array $events, string $groupBy): array
    {
        $groups = [];
        $userCounts = [];

        foreach ($events as $event) {
            $key = (string) ($event[$groupBy] ?? 'unknown');

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'value' => $key,
                    'count' => 0,
                    'users' => [],
                ];
            }

            ++$groups[$key]['count'];

            // Track unique users
            $userId = $event['user_id'] ?? $event['device_id'] ?? null;
            if (null !== $userId) {
                $groups[$key]['users'][$userId] = true;
            }
        }

        // Convert user arrays to counts and sort by count descending
        $results = [];
        foreach ($groups as $key => $group) {
            $results[] = [
                'value' => $group['value'],
                'count' => $group['count'],
                'users' => count($group['users']),
            ];
        }

        usort($results, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $results;
    }
}
