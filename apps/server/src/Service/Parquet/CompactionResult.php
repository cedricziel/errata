<?php

declare(strict_types=1);

namespace App\Service\Parquet;

/**
 * Result of a partition compaction operation.
 */
final readonly class CompactionResult
{
    /**
     * @param array<string> $outputFiles
     */
    public function __construct(
        public string $partitionPath,
        public array $outputFiles,
        public int $filesRemoved,
        public int $eventsCount,
        public bool $success,
        public ?string $error = null,
    ) {
    }

    public static function success(
        string $partitionPath,
        array $outputFiles,
        int $filesRemoved,
        int $eventsCount,
    ): self {
        return new self(
            $partitionPath,
            $outputFiles,
            $filesRemoved,
            $eventsCount,
            true,
        );
    }

    public static function failure(string $partitionPath, string $error): self
    {
        return new self(
            $partitionPath,
            [],
            0,
            0,
            false,
            $error,
        );
    }
}
