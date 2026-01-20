<?php

declare(strict_types=1);

namespace App\Service\QueryBuilder;

use App\Enum\QueryStatus;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Stores and retrieves async query state and results.
 *
 * Uses Symfony Cache to persist query execution state, allowing the SSE endpoint
 * to poll for updates and stream results to clients.
 */
class AsyncQueryResultStore
{
    private const CACHE_PREFIX = 'async_query_';
    private const TTL_PENDING = 3600; // 1 hour for pending queries
    private const TTL_COMPLETED = 300; // 5 minutes for completed results

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Initialize a new query in pending state.
     *
     * @param array<string, mixed> $queryRequest Serialized query request data
     */
    public function initializeQuery(
        string $queryId,
        array $queryRequest,
        string $userId,
        ?string $organizationId = null,
    ): void {
        $item = $this->cache->getItem($this->getCacheKey($queryId));
        $item->set([
            'queryId' => $queryId,
            'status' => QueryStatus::PENDING->value,
            'progress' => 0,
            'queryRequest' => $queryRequest,
            'userId' => $userId,
            'organizationId' => $organizationId,
            'result' => null,
            'error' => null,
            'cancelRequested' => false,
            'createdAt' => time(),
            'updatedAt' => time(),
        ]);
        $item->expiresAfter(self::TTL_PENDING);
        $this->cache->save($item);
    }

    /**
     * Mark a query as in progress.
     */
    public function markInProgress(string $queryId, int $progress = 0): void
    {
        $state = $this->getQueryState($queryId);
        if (null === $state) {
            return;
        }

        $state['status'] = QueryStatus::IN_PROGRESS->value;
        $state['progress'] = $progress;
        $state['updatedAt'] = time();

        $this->saveState($queryId, $state, self::TTL_PENDING);
    }

    /**
     * Update progress percentage.
     */
    public function updateProgress(string $queryId, int $progress): void
    {
        $state = $this->getQueryState($queryId);
        if (null === $state) {
            return;
        }

        $state['progress'] = min(100, max(0, $progress));
        $state['updatedAt'] = time();

        $this->saveState($queryId, $state, self::TTL_PENDING);
    }

    /**
     * Store successful query result.
     *
     * @param array<string, mixed> $result
     */
    public function storeResult(string $queryId, array $result): void
    {
        $state = $this->getQueryState($queryId);
        if (null === $state) {
            return;
        }

        $state['status'] = QueryStatus::COMPLETED->value;
        $state['progress'] = 100;
        $state['result'] = $result;
        $state['completedAt'] = time();
        $state['updatedAt'] = time();

        $this->saveState($queryId, $state, self::TTL_COMPLETED);
    }

    /**
     * Store query error.
     */
    public function storeError(string $queryId, string $error): void
    {
        $state = $this->getQueryState($queryId);
        if (null === $state) {
            return;
        }

        $state['status'] = QueryStatus::FAILED->value;
        $state['error'] = $error;
        $state['completedAt'] = time();
        $state['updatedAt'] = time();

        $this->saveState($queryId, $state, self::TTL_COMPLETED);
    }

    /**
     * Request cancellation of a running query.
     */
    public function requestCancellation(string $queryId): bool
    {
        $state = $this->getQueryState($queryId);
        if (null === $state) {
            return false;
        }

        // Only allow cancellation of non-terminal queries
        $status = QueryStatus::from($state['status']);
        if ($status->isTerminal()) {
            return false;
        }

        $state['cancelRequested'] = true;
        $state['updatedAt'] = time();

        $this->saveState($queryId, $state, self::TTL_PENDING);

        return true;
    }

    /**
     * Mark query as cancelled.
     */
    public function markCancelled(string $queryId): void
    {
        $state = $this->getQueryState($queryId);
        if (null === $state) {
            return;
        }

        $state['status'] = QueryStatus::CANCELLED->value;
        $state['completedAt'] = time();
        $state['updatedAt'] = time();

        $this->saveState($queryId, $state, self::TTL_COMPLETED);
    }

    /**
     * Check if cancellation has been requested.
     */
    public function isCancelled(string $queryId): bool
    {
        $state = $this->getQueryState($queryId);
        if (null === $state) {
            return false;
        }

        return $state['cancelRequested'] ?? false;
    }

    /**
     * Get the current state of a query.
     *
     * @return array<string, mixed>|null
     */
    public function getQueryState(string $queryId): ?array
    {
        $item = $this->cache->getItem($this->getCacheKey($queryId));
        if (!$item->isHit()) {
            return null;
        }

        return $item->get();
    }

    /**
     * Get the current status of a query.
     */
    public function getStatus(string $queryId): ?QueryStatus
    {
        $state = $this->getQueryState($queryId);
        if (null === $state) {
            return null;
        }

        return QueryStatus::from($state['status']);
    }

    /**
     * Delete a query from the cache.
     */
    public function deleteQuery(string $queryId): void
    {
        $this->cache->deleteItem($this->getCacheKey($queryId));
    }

    private function getCacheKey(string $queryId): string
    {
        return self::CACHE_PREFIX.$queryId;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(string $queryId, array $state, int $ttl): void
    {
        $item = $this->cache->getItem($this->getCacheKey($queryId));
        $item->set($state);
        $item->expiresAfter($ttl);
        $this->cache->save($item);
    }
}
