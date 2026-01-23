<?php

declare(strict_types=1);

namespace App\Service\Parquet;

/**
 * Summary of a full compaction run.
 */
final readonly class CompactionSummary
{
    /**
     * @param array<CompactionResult> $results
     */
    public function __construct(
        public int $partitionsFound,
        public int $partitionsCompacted,
        public int $blocksCreated,
        public int $filesRemoved,
        public int $totalEvents,
        public int $errors,
        public array $results = [],
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors > 0;
    }

    public function isEmpty(): bool
    {
        return 0 === $this->partitionsFound;
    }
}
