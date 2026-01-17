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
     * Compute facets from a set of events.
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
        $facets = [];

        foreach ($facetableAttributes as $attribute => $meta) {
            $facets[] = $this->computeFacet(
                events: $events,
                attribute: $attribute,
                label: $meta['label'],
                facetType: $meta['facetType'],
                expanded: $meta['facetExpanded'],
                activeValue: $activeFilterValues[$attribute] ?? null,
            );
        }

        return $facets;
    }

    /**
     * Compute a single facet.
     *
     * @param array<array<string, mixed>> $events
     * @param string|array<string>|null   $activeValue
     */
    private function computeFacet(
        array $events,
        string $attribute,
        string $label,
        string $facetType,
        bool $expanded,
        string|array|null $activeValue = null,
    ): Facet {
        // Count occurrences of each value
        $counts = [];
        foreach ($events as $event) {
            $value = $event[$attribute] ?? null;
            if (null === $value || '' === $value) {
                continue;
            }

            $stringValue = (string) $value;
            $counts[$stringValue] = ($counts[$stringValue] ?? 0) + 1;
        }

        // Sort by count descending
        arsort($counts);

        // Create facet values
        $values = [];
        $totalCount = 0;
        foreach ($counts as $value => $count) {
            $isSelected = $this->isValueSelected($value, $activeValue);

            $values[] = new FacetValue(
                value: (string) $value,
                count: $count,
                selected: $isSelected,
            );
            $totalCount += $count;
        }

        // Limit the number of values for display (but keep track of total)
        $displayValues = array_slice($values, 0, self::MAX_FACET_VALUES);

        return new Facet(
            attribute: $attribute,
            label: $label,
            type: $facetType,
            values: $displayValues,
            expanded: $expanded,
            totalCount: count($values),
        );
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
