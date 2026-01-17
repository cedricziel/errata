<?php

declare(strict_types=1);

namespace App\DTO\QueryBuilder;

/**
 * Represents a complete query request with filters, grouping, and pagination.
 */
class QueryRequest
{
    /**
     * @param array<QueryFilter> $filters
     */
    public function __construct(
        public array $filters = [],
        public ?string $groupBy = null,
        public int $page = 1,
        public int $limit = 50,
        public ?string $projectId = null,
        public ?\DateTimeInterface $startDate = null,
        public ?\DateTimeInterface $endDate = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $filters = [];
        if (isset($data['filters']) && is_array($data['filters'])) {
            foreach ($data['filters'] as $filterData) {
                if (is_array($filterData) && !empty($filterData['attribute'])) {
                    $filters[] = QueryFilter::fromArray($filterData);
                }
            }
        }

        $startDate = null;
        if (!empty($data['startDate'])) {
            $startDate = new \DateTimeImmutable($data['startDate']);
        }

        $endDate = null;
        if (!empty($data['endDate'])) {
            $endDate = new \DateTimeImmutable($data['endDate']);
        }

        return new self(
            filters: $filters,
            groupBy: $data['groupBy'] ?? null,
            page: (int) ($data['page'] ?? 1),
            limit: (int) ($data['limit'] ?? 50),
            projectId: $data['projectId'] ?? null,
            startDate: $startDate,
            endDate: $endDate,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'filters' => array_map(fn (QueryFilter $f) => $f->toArray(), $this->filters),
            'groupBy' => $this->groupBy,
            'page' => $this->page,
            'limit' => $this->limit,
            'projectId' => $this->projectId,
            'startDate' => $this->startDate?->format('Y-m-d H:i:s'),
            'endDate' => $this->endDate?->format('Y-m-d H:i:s'),
        ];
    }

    public function getOffset(): int
    {
        return ($this->page - 1) * $this->limit;
    }
}
