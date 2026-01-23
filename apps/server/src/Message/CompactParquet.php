<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message to trigger Parquet compaction.
 *
 * This message is dispatched by the scheduler to compact parquet files
 * in partitions. By default compacts all partitions with multiple files.
 * Optionally filter to a specific date.
 */
final class CompactParquet
{
    public function __construct(
        public readonly ?string $date = null,
    ) {
    }
}
