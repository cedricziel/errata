<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message for executing a query asynchronously.
 *
 * Dispatched to the async transport and handled by ExecuteQueryHandler.
 * Results are stored in AsyncQueryResultStore for SSE retrieval.
 */
class ExecuteQuery
{
    /**
     * @param string               $queryId        Unique ID for tracking this query
     * @param array<string, mixed> $queryRequest   Serialized QueryRequest data
     * @param string               $userId         User who submitted the query
     * @param string|null          $organizationId Optional organization context
     */
    public function __construct(
        public readonly string $queryId,
        public readonly array $queryRequest,
        public readonly string $userId,
        public readonly ?string $organizationId = null,
    ) {
    }
}
