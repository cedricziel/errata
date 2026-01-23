<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CompactParquet;
use App\Service\Parquet\ParquetCompactionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for CompactParquet messages.
 *
 * Runs parquet compaction when triggered by the scheduler.
 */
#[AsMessageHandler]
final class CompactParquetHandler
{
    public function __construct(
        private readonly ParquetCompactionService $compactionService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(CompactParquet $message): void
    {
        $this->logger->info('Starting scheduled Parquet compaction', [
            'date' => $message->date,
        ]);

        $summary = $this->compactionService->compact(
            date: $message->date
        );

        if ($summary->isEmpty()) {
            $this->logger->info('No partitions needed compaction');

            return;
        }

        if ($summary->hasErrors()) {
            $this->logger->error('Parquet compaction completed with errors', [
                'partitions_found' => $summary->partitionsFound,
                'partitions_compacted' => $summary->partitionsCompacted,
                'blocks_created' => $summary->blocksCreated,
                'files_removed' => $summary->filesRemoved,
                'errors' => $summary->errors,
            ]);

            throw new \RuntimeException(sprintf('Parquet compaction had %d errors out of %d partitions', $summary->errors, $summary->partitionsFound));
        }

        $this->logger->info('Parquet compaction completed successfully', [
            'partitions_compacted' => $summary->partitionsCompacted,
            'blocks_created' => $summary->blocksCreated,
            'files_removed' => $summary->filesRemoved,
            'total_events' => $summary->totalEvents,
        ]);
    }
}
