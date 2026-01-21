<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\DTO\QueryBuilder\QueryRequest;
use App\Message\ExecuteQuery;
use App\Service\QueryBuilder\AsyncQueryResultStore;
use App\Service\QueryBuilder\EventQueryService;
use App\Service\QueryBuilder\FacetBatchDispatcher;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for executing queries asynchronously.
 *
 * Executes the query via EventQueryService with priority facets,
 * stores results in AsyncQueryResultStore for SSE retrieval,
 * then dispatches deferred facet batches for parallel computation.
 */
#[AsMessageHandler]
class ExecuteQueryHandler
{
    public function __construct(
        private readonly EventQueryService $eventQueryService,
        private readonly AsyncQueryResultStore $resultStore,
        private readonly FacetBatchDispatcher $facetBatchDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExecuteQuery $message): void
    {
        $span = $this->startSpan('execute_async_query');
        $span->setAttribute('query.id', $message->queryId);
        $span->setAttribute('user.id', $message->userId);

        try {
            // Check if cancelled before starting
            if ($this->resultStore->isCancelled($message->queryId)) {
                $this->resultStore->markCancelled($message->queryId);
                $span->setStatus(StatusCode::STATUS_OK, 'Query cancelled before execution');
                $this->logger->info('Query cancelled before execution', ['query_id' => $message->queryId]);

                return;
            }

            // Mark as in progress
            $this->resultStore->markInProgress($message->queryId, 10);

            $this->logger->info('Starting async query execution', [
                'query_id' => $message->queryId,
                'user_id' => $message->userId,
            ]);

            // Build the QueryRequest from serialized data
            $queryRequest = QueryRequest::fromArray($message->queryRequest);

            // Update progress - query is being executed
            $this->resultStore->updateProgress($message->queryId, 30);

            // Check for cancellation mid-execution
            if ($this->resultStore->isCancelled($message->queryId)) {
                $this->resultStore->markCancelled($message->queryId);
                $span->setStatus(StatusCode::STATUS_OK, 'Query cancelled during execution');
                $this->logger->info('Query cancelled during execution', ['query_id' => $message->queryId]);

                return;
            }

            // Execute the query with priority facets only
            $result = $this->eventQueryService->executeQueryWithPriorityFacets(
                $queryRequest,
                $message->organizationId,
            );

            // Update progress - processing results
            $this->resultStore->updateProgress($message->queryId, 80);

            // Check for cancellation after execution
            if ($this->resultStore->isCancelled($message->queryId)) {
                $this->resultStore->markCancelled($message->queryId);
                $span->setStatus(StatusCode::STATUS_OK, 'Query cancelled after execution');
                $this->logger->info('Query cancelled after execution', ['query_id' => $message->queryId]);

                return;
            }

            // Serialize the result
            $resultData = [
                'events' => $result->events,
                'total' => $result->total,
                'facets' => array_map(fn ($f) => $f->toArray(), $result->facets),
                'groupedResults' => $result->groupedResults,
                'page' => $result->page,
                'limit' => $result->limit,
            ];

            // Store the result with priority facets
            $this->resultStore->storeResult($message->queryId, $resultData);

            // Initialize facet batch tracking
            $batchIds = array_keys(FacetBatchDispatcher::getBatches());
            $this->resultStore->initializeFacetBatches($message->queryId, $batchIds);

            // Dispatch deferred facet batches for parallel computation
            $queryContext = $message->queryRequest;
            $queryContext['organizationId'] = $message->organizationId;
            $this->facetBatchDispatcher->dispatchDeferredFacets($message->queryId, $queryContext);

            $span->setStatus(StatusCode::STATUS_OK);
            $span->setAttribute('result.total', $result->total);

            $this->logger->info('Async query completed, deferred facets dispatched', [
                'query_id' => $message->queryId,
                'total_results' => $result->total,
                'deferred_batches' => count($batchIds),
            ]);
        } catch (\Throwable $e) {
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->recordException($e);

            $this->resultStore->storeError($message->queryId, $e->getMessage());

            $this->logger->error('Async query failed', [
                'query_id' => $message->queryId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $span->end();
        }
    }

    private function startSpan(string $name): SpanInterface
    {
        return Globals::tracerProvider()->getTracer('errata')
            ->spanBuilder($name)
            ->startSpan();
    }
}
