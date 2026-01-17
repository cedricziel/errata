<?php

declare(strict_types=1);

namespace App\DTO\QueryBuilder;

/**
 * Represents the result of a query including events, facets, and pagination info.
 */
class QueryResult
{
    /**
     * @param array<array<string, mixed>> $events
     * @param array<Facet>                $facets
     * @param array<array<string, mixed>> $groupedResults
     */
    public function __construct(
        public array $events = [],
        public int $total = 0,
        public array $facets = [],
        public array $groupedResults = [],
        public int $page = 1,
        public int $limit = 50,
    ) {
    }

    public function getTotalPages(): int
    {
        if ($this->limit <= 0) {
            return 1;
        }

        return (int) ceil($this->total / $this->limit);
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->getTotalPages();
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'events' => $this->events,
            'total' => $this->total,
            'facets' => array_map(fn (Facet $f) => $f->toArray(), $this->facets),
            'groupedResults' => $this->groupedResults,
            'page' => $this->page,
            'limit' => $this->limit,
            'totalPages' => $this->getTotalPages(),
            'hasNextPage' => $this->hasNextPage(),
            'hasPreviousPage' => $this->hasPreviousPage(),
        ];
    }
}
