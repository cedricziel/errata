<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message for computing a batch of facets asynchronously.
 *
 * Dispatched by ExecuteQueryHandler after the main query completes.
 * Results are appended to AsyncQueryResultStore for SSE streaming.
 */
class ComputeFacetBatch
{
    /**
     * @param string               $queryId      The query ID to associate results with
     * @param string               $batchId      Batch identifier (e.g., "device", "app", "trace", "user")
     * @param array<string>        $attributes   Facet attributes to compute in this batch
     * @param array<string, mixed> $queryContext Serialized query context (filters, date range, org/project)
     */
    public function __construct(
        public readonly string $queryId,
        public readonly string $batchId,
        public readonly array $attributes,
        public readonly array $queryContext,
    ) {
    }
}
