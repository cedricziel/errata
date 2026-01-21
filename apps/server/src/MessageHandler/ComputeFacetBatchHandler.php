<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\DTO\QueryBuilder\QueryRequest;
use App\Message\ComputeFacetBatch;
use App\Service\Parquet\ParquetReaderService;
use App\Service\QueryBuilder\AsyncQueryResultStore;
use App\Service\QueryBuilder\AttributeMetadataService;
use App\Service\QueryBuilder\FacetAggregationService;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for computing a batch of facets asynchronously.
 *
 * Reads events with only the columns needed for this batch,
 * computes facet counts, and appends results to AsyncQueryResultStore.
 */
#[AsMessageHandler]
class ComputeFacetBatchHandler
{
    public function __construct(
        private readonly ParquetReaderService $parquetReader,
        private readonly AsyncQueryResultStore $resultStore,
        private readonly FacetAggregationService $facetAggregation,
        private readonly AttributeMetadataService $attributeMetadata,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ComputeFacetBatch $message): void
    {
        $span = $this->startSpan('compute_facet_batch');
        $span->setAttribute('query.id', $message->queryId);
        $span->setAttribute('batch.id', $message->batchId);
        $span->setAttribute('batch.attributes', implode(',', $message->attributes));

        try {
            // Check if query was cancelled
            if ($this->resultStore->isCancelled($message->queryId)) {
                $this->logger->info('Facet batch skipped - query cancelled', [
                    'query_id' => $message->queryId,
                    'batch_id' => $message->batchId,
                ]);
                $span->setStatus(StatusCode::STATUS_OK, 'Query cancelled');

                return;
            }

            $this->logger->info('Computing facet batch', [
                'query_id' => $message->queryId,
                'batch_id' => $message->batchId,
                'attributes' => $message->attributes,
            ]);

            // Build QueryRequest from context
            $queryRequest = QueryRequest::fromArray($message->queryContext);

            // Compute facet counts for this batch's attributes
            $facetCounts = $this->computeFacetCounts(
                $message->attributes,
                $queryRequest,
                $message->queryContext['organizationId'] ?? null,
            );

            // Build Facet objects from counts
            $facets = $this->buildFacetsFromCounts($facetCounts, $message->attributes, $queryRequest->filters);

            // Append facets to the query result
            $this->resultStore->appendFacets(
                $message->queryId,
                $message->batchId,
                array_map(fn ($f) => $f->toArray(), $facets),
            );

            $span->setStatus(StatusCode::STATUS_OK);
            $span->setAttribute('facet.count', count($facets));

            $this->logger->info('Facet batch completed', [
                'query_id' => $message->queryId,
                'batch_id' => $message->batchId,
                'facet_count' => count($facets),
            ]);
        } catch (\Throwable $e) {
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->recordException($e);

            $this->logger->error('Facet batch failed', [
                'query_id' => $message->queryId,
                'batch_id' => $message->batchId,
                'error' => $e->getMessage(),
            ]);

            // Mark the batch as failed but don't throw - other batches can continue
            $this->resultStore->markFacetBatchFailed($message->queryId, $message->batchId, $e->getMessage());
        } finally {
            $span->end();
        }
    }

    /**
     * Compute facet counts for the specified attributes.
     *
     * @param array<string> $attributes
     *
     * @return array<string, array<string, int>>
     */
    private function computeFacetCounts(
        array $attributes,
        QueryRequest $queryRequest,
        ?string $organizationId,
    ): array {
        $facetCounts = [];
        foreach ($attributes as $attr) {
            $facetCounts[$attr] = [];
        }

        // Read events with only the columns needed for this batch
        $columns = array_merge($attributes, ['timestamp']);

        foreach ($this->parquetReader->readEventsWithColumns(
            organizationId: $organizationId,
            projectId: $queryRequest->projectId,
            from: $queryRequest->startDate,
            to: $queryRequest->endDate,
            columns: $columns,
            filters: $queryRequest->filters,
        ) as $event) {
            // Collect facet counts
            foreach ($attributes as $attr) {
                $value = $event[$attr] ?? null;
                if (null !== $value && '' !== $value) {
                    $stringValue = (string) $value;
                    $facetCounts[$attr][$stringValue] = ($facetCounts[$attr][$stringValue] ?? 0) + 1;
                }
            }
        }

        return $facetCounts;
    }

    /**
     * Build Facet objects from counts for only the batch's attributes.
     *
     * @param array<string, array<string, int>>        $facetCounts
     * @param array<string>                            $attributes
     * @param array<\App\DTO\QueryBuilder\QueryFilter> $activeFilters
     *
     * @return array<\App\DTO\QueryBuilder\Facet>
     */
    private function buildFacetsFromCounts(array $facetCounts, array $attributes, array $activeFilters): array
    {
        $facetableAttributes = $this->attributeMetadata->getFacetableAttributes();

        // Filter to only the attributes we're processing
        $filteredCounts = [];
        foreach ($attributes as $attr) {
            if (isset($facetableAttributes[$attr])) {
                $filteredCounts[$attr] = $facetCounts[$attr] ?? [];
            }
        }

        return $this->facetAggregation->computeFacetsFromCounts($filteredCounts, $activeFilters);
    }

    private function startSpan(string $name): SpanInterface
    {
        return Globals::tracerProvider()->getTracer('errata')
            ->spanBuilder($name)
            ->startSpan();
    }
}
