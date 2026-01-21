<?php

declare(strict_types=1);

namespace App\Service\QueryBuilder;

use App\Message\ComputeFacetBatch;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches deferred facet batches for parallel computation.
 *
 * Deferred facets are computed after the main query returns results,
 * allowing clients to receive initial data faster while facets load progressively.
 */
class FacetBatchDispatcher
{
    /**
     * Facet batches grouped by logical category.
     * Each batch reads the same Parquet files but only extracts columns for its attributes.
     *
     * @var array<string, array<string>>
     */
    private const BATCHES = [
        'device' => ['device_model', 'os_name', 'os_version'],
        'app' => ['app_version', 'app_build'],
        'trace' => ['operation', 'span_status'],
        'user' => ['user_id', 'locale'],
    ];

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Dispatch deferred facet batches for parallel computation.
     *
     * @param string               $queryId      The query ID to associate results with
     * @param array<string, mixed> $queryContext Serialized query context (filters, date range, org/project)
     */
    public function dispatchDeferredFacets(string $queryId, array $queryContext): void
    {
        foreach (self::BATCHES as $batchId => $attributes) {
            $this->bus->dispatch(new ComputeFacetBatch(
                queryId: $queryId,
                batchId: $batchId,
                attributes: $attributes,
                queryContext: $queryContext,
            ));
        }
    }

    /**
     * Get the batch configuration.
     *
     * @return array<string, array<string>>
     */
    public static function getBatches(): array
    {
        return self::BATCHES;
    }

    /**
     * Get all deferred attribute names (attributes not computed with the main query).
     *
     * @return array<string>
     */
    public static function getDeferredAttributes(): array
    {
        $attributes = [];
        foreach (self::BATCHES as $batchAttributes) {
            $attributes = array_merge($attributes, $batchAttributes);
        }

        return $attributes;
    }
}
