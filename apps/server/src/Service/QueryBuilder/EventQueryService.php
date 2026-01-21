<?php

declare(strict_types=1);

namespace App\Service\QueryBuilder;

use App\DTO\QueryBuilder\QueryFilter;
use App\DTO\QueryBuilder\QueryRequest;
use App\DTO\QueryBuilder\QueryResult;
use App\Service\Parquet\ParquetReaderService;

/**
 * Executes queries against the Parquet event storage using DataFrame operations.
 */
class EventQueryService
{
    public function __construct(
        private readonly ParquetReaderService $parquetReader,
        private readonly FacetAggregationService $facetAggregation,
        private readonly AttributeMetadataService $attributeMetadata,
    ) {
    }

    /**
     * Execute a query and return results with facets.
     *
     * Uses single-pass processing for efficiency:
     * 1. Read events from Parquet files via DataFrame API
     * 2. Apply filters
     * 3. Compute facets and collect events in one pass
     */
    public function executeQuery(QueryRequest $request, ?string $organizationId = null): QueryResult
    {
        // Determine which columns we need
        $columns = $this->getRequiredColumns($request);

        // Single-pass processing: read, filter, compute facets and collect events
        $result = $this->processEventsInSinglePass(
            organizationId: $organizationId,
            projectId: $request->projectId,
            startDate: $request->startDate,
            endDate: $request->endDate,
            filters: $request->filters,
            columns: $columns,
            groupBy: $request->groupBy,
            limit: $request->limit,
            offset: $request->getOffset(),
        );

        // Handle empty results
        if (0 === $result['total']) {
            return new QueryResult(
                events: [],
                total: 0,
                facets: $this->facetAggregation->createEmptyFacets($request->filters),
                groupedResults: [],
                page: $request->page,
                limit: $request->limit,
            );
        }

        // Compute facets from the collected facet data
        $facets = $this->facetAggregation->computeFacetsFromCounts(
            $result['facetCounts'],
            $request->filters,
        );

        return new QueryResult(
            events: $result['events'],
            total: $result['total'],
            facets: $facets,
            groupedResults: $result['groupedResults'],
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
     * Process events in a single pass: filter, compute facets, and collect paginated results.
     *
     * @param array<QueryFilter> $filters
     * @param array<string>      $columns
     *
     * @return array{events: array<array<string, mixed>>, total: int, facetCounts: array<string, array<string, int>>, groupedResults: array<array<string, mixed>>}
     */
    private function processEventsInSinglePass(
        ?string $organizationId,
        ?string $projectId,
        ?\DateTimeInterface $startDate,
        ?\DateTimeInterface $endDate,
        array $filters,
        array $columns,
        ?string $groupBy,
        int $limit,
        int $offset,
    ): array {
        $facetableAttributes = $this->attributeMetadata->getFacetableAttributes();
        $facetCounts = [];
        foreach ($facetableAttributes as $attr => $_) {
            $facetCounts[$attr] = [];
        }

        $allEvents = [];
        $groupedData = [];
        $total = 0;

        // Read events using the new DataFrame-based reader with column pruning
        foreach ($this->parquetReader->readEventsWithColumns(
            organizationId: $organizationId,
            projectId: $projectId,
            from: $startDate,
            to: $endDate,
            columns: $columns,
            filters: $filters,
        ) as $event) {
            ++$total;

            // Collect facet counts in the same pass
            foreach ($facetableAttributes as $attr => $_) {
                $value = $event[$attr] ?? null;
                if (null !== $value && '' !== $value) {
                    $stringValue = (string) $value;
                    $facetCounts[$attr][$stringValue] = ($facetCounts[$attr][$stringValue] ?? 0) + 1;
                }
            }

            // Handle grouping
            if (null !== $groupBy) {
                $key = (string) ($event[$groupBy] ?? 'unknown');
                if (!isset($groupedData[$key])) {
                    $groupedData[$key] = [
                        'value' => $key,
                        'count' => 0,
                        'users' => [],
                    ];
                }
                ++$groupedData[$key]['count'];

                $userId = $event['user_id'] ?? $event['device_id'] ?? null;
                if (null !== $userId) {
                    $groupedData[$key]['users'][$userId] = true;
                }
            } else {
                // Collect all events for sorting and pagination
                $allEvents[] = $event;
            }
        }

        // Process results based on grouping
        $events = [];
        $groupedResults = [];

        if (null !== $groupBy) {
            // Convert grouped data to results
            foreach ($groupedData as $group) {
                $groupedResults[] = [
                    'value' => $group['value'],
                    'count' => $group['count'],
                    'users' => count($group['users']),
                ];
            }
            usort($groupedResults, fn (array $a, array $b) => $b['count'] <=> $a['count']);
        } else {
            // Sort by timestamp descending
            usort($allEvents, function (array $a, array $b) {
                $tsA = $a['timestamp'] ?? 0;
                $tsB = $b['timestamp'] ?? 0;

                return $tsB <=> $tsA;
            });

            // Apply pagination
            $events = array_slice($allEvents, $offset, $limit);
        }

        return [
            'events' => $events,
            'total' => $total,
            'facetCounts' => $facetCounts,
            'groupedResults' => $groupedResults,
        ];
    }

    /**
     * Determine which columns are needed for the query.
     *
     * @return array<string>
     */
    private function getRequiredColumns(QueryRequest $request): array
    {
        $columns = ['timestamp', 'event_id', 'user_id', 'device_id'];

        // Add filter attributes
        foreach ($request->filters as $filter) {
            $columns[] = $filter->attribute;
        }

        // Add facetable attributes
        foreach ($this->attributeMetadata->getFacetableAttributes() as $attr => $_) {
            $columns[] = $attr;
        }

        // Add groupBy attribute
        if (null !== $request->groupBy) {
            $columns[] = $request->groupBy;
        }

        return array_unique($columns);
    }
}
