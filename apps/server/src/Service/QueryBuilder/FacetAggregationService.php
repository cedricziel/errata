<?php

declare(strict_types=1);

namespace App\Service\QueryBuilder;

use App\DTO\QueryBuilder\Facet;
use App\DTO\QueryBuilder\FacetValue;
use App\DTO\QueryBuilder\QueryFilter;

/**
 * Computes facet aggregations from event data.
 */
class FacetAggregationService
{
    private const MAX_FACET_VALUES = 10;

    public function __construct(
        private readonly AttributeMetadataService $attributeMetadata,
    ) {
    }

    /**
     * Compute facets from a set of events (legacy method, kept for backward compatibility).
     *
     * @param array<array<string, mixed>> $events
     * @param array<QueryFilter>          $activeFilters
     *
     * @return array<Facet>
     */
    public function computeFacets(array $events, array $activeFilters = []): array
    {
        $facetableAttributes = $this->attributeMetadata->getFacetableAttributes();
        $activeFilterValues = $this->extractActiveFilterValues($activeFilters);

        // Compute all facet counts in a single pass
        $facetCounts = [];
        foreach ($facetableAttributes as $attr => $_) {
            $facetCounts[$attr] = [];
        }

        foreach ($events as $event) {
            foreach ($facetableAttributes as $attr => $_) {
                $value = $event[$attr] ?? null;
                if (null !== $value && '' !== $value) {
                    $stringValue = (string) $value;
                    $facetCounts[$attr][$stringValue] = ($facetCounts[$attr][$stringValue] ?? 0) + 1;
                }
            }
        }

        return $this->computeFacetsFromCounts($facetCounts, $activeFilters);
    }

    /**
     * Compute facets from pre-computed counts (single-pass optimization).
     *
     * @param array<string, array<string, int>> $facetCounts   Map of attribute => [value => count]
     * @param array<QueryFilter>                $activeFilters
     *
     * @return array<Facet>
     */
    public function computeFacetsFromCounts(array $facetCounts, array $activeFilters = []): array
    {
        $facetableAttributes = $this->attributeMetadata->getFacetableAttributes();
        $activeFilterValues = $this->extractActiveFilterValues($activeFilters);
        $facets = [];

        foreach ($facetableAttributes as $attribute => $meta) {
            $counts = $facetCounts[$attribute] ?? [];

            // Sort by count descending
            arsort($counts);

            // Create facet values
            $values = [];
            foreach ($counts as $value => $count) {
                $isSelected = $this->isValueSelected((string) $value, $activeFilterValues[$attribute] ?? null);

                $values[] = new FacetValue(
                    value: (string) $value,
                    count: $count,
                    selected: $isSelected,
                );
            }

            // Limit the number of values for display
            $displayValues = array_slice($values, 0, self::MAX_FACET_VALUES);

            $facets[] = new Facet(
                attribute: $attribute,
                label: $meta['label'],
                type: $meta['facetType'],
                values: $displayValues,
                expanded: $meta['facetExpanded'],
                totalCount: count($values),
            );
        }

        return $facets;
    }

    /**
     * Create empty facets with no values (for when there are no results).
     *
     * @param array<QueryFilter> $activeFilters
     *
     * @return array<Facet>
     */
    public function createEmptyFacets(array $activeFilters = []): array
    {
        $facetableAttributes = $this->attributeMetadata->getFacetableAttributes();
        $facets = [];

        foreach ($facetableAttributes as $attribute => $meta) {
            $facets[] = new Facet(
                attribute: $attribute,
                label: $meta['label'],
                type: $meta['facetType'],
                values: [],
                expanded: $meta['facetExpanded'],
                totalCount: 0,
            );
        }

        return $facets;
    }

    /**
     * Check if a value is selected in the active filters.
     *
     * @param string|array<string>|null $activeValue
     */
    private function isValueSelected(string $value, string|array|null $activeValue): bool
    {
        if (null === $activeValue) {
            return false;
        }

        if (is_array($activeValue)) {
            return in_array($value, $activeValue, true);
        }

        return $value === $activeValue;
    }

    /**
     * Extract active filter values by attribute for facet selection.
     *
     * @param array<QueryFilter> $filters
     *
     * @return array<string, string|array<string>>
     */
    private function extractActiveFilterValues(array $filters): array
    {
        $values = [];

        foreach ($filters as $filter) {
            // Only extract values for equality and 'in' operators
            if (QueryFilter::OPERATOR_EQ === $filter->operator) {
                $values[$filter->attribute] = (string) $filter->value;
            } elseif (QueryFilter::OPERATOR_IN === $filter->operator && is_array($filter->value)) {
                $values[$filter->attribute] = array_map('strval', $filter->value);
            }
        }

        return $values;
    }

    /**
     * Get all unique values for a specific attribute from events.
     *
     * @param array<array<string, mixed>> $events
     *
     * @return array<string, int>
     */
    public function getAttributeValues(array $events, string $attribute): array
    {
        $counts = [];
        foreach ($events as $event) {
            $value = $event[$attribute] ?? null;
            if (null === $value || '' === $value) {
                continue;
            }

            $stringValue = (string) $value;
            $counts[$stringValue] = ($counts[$stringValue] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }
}
