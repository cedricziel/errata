<?php

declare(strict_types=1);

namespace App\DTO\QueryBuilder;

/**
 * Represents a single value within a facet with its count and selection state.
 */
class FacetValue
{
    public function __construct(
        public string $value,
        public int $count,
        public bool $selected = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'count' => $this->count,
            'selected' => $this->selected,
        ];
    }
}
