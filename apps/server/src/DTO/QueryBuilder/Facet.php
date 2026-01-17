<?php

declare(strict_types=1);

namespace App\DTO\QueryBuilder;

/**
 * Represents a facet definition for faceted navigation.
 */
class Facet
{
    public const TYPE_SINGLE = 'single';
    public const TYPE_MULTI = 'multi';
    public const TYPE_COLLAPSIBLE = 'collapsible';

    /**
     * @param array<FacetValue> $values
     */
    public function __construct(
        public string $attribute,
        public string $label,
        public string $type,
        public array $values = [],
        public bool $expanded = true,
        public int $totalCount = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attribute' => $this->attribute,
            'label' => $this->label,
            'type' => $this->type,
            'values' => array_map(fn (FacetValue $v) => $v->toArray(), $this->values),
            'expanded' => $this->expanded,
            'totalCount' => $this->totalCount,
        ];
    }

    public function getSelectedValue(): ?string
    {
        foreach ($this->values as $value) {
            if ($value->selected) {
                return $value->value;
            }
        }

        return null;
    }

    /**
     * @return array<string>
     */
    public function getSelectedValues(): array
    {
        $selected = [];
        foreach ($this->values as $value) {
            if ($value->selected) {
                $selected[] = $value->value;
            }
        }

        return $selected;
    }
}
