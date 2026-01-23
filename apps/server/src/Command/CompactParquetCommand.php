<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Parquet\ParquetCompactionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command to compact Parquet files within partitions.
 *
 * This command is a thin wrapper around ParquetCompactionService.
 * It provides CLI-specific features like dry-run mode and progress output.
 */
#[AsCommand(
    name: 'app:parquet:compact',
    description: 'Compact multiple Parquet files in partitions into single files',
)]
class CompactParquetCommand extends Command
{
    public function __construct(
        private readonly ParquetCompactionService $compactionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', 'd', InputOption::VALUE_REQUIRED, 'Compact specific date (YYYY-MM-DD) only')
            ->addOption('organization', 'o', InputOption::VALUE_REQUIRED, 'Filter by organization ID')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Filter by project ID')
            ->addOption('event-type', 't', InputOption::VALUE_REQUIRED, 'Filter by event type')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be compacted without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $date = $input->getOption('date');
        $organization = $input->getOption('organization');
        $project = $input->getOption('project');
        $eventType = $input->getOption('event-type');

        if ($dryRun) {
            $io->note('Running in dry-run mode - no changes will be made');
        }

        $io->info(sprintf('Storage type: %s', $this->compactionService->getStorageType()));

        if ($date) {
            $io->info("Compacting partitions for date: {$date}");
        } else {
            $io->info('Compacting all partitions with uncompacted files');
        }

        // In dry-run mode, just show what would be compacted
        if ($dryRun) {
            $partitions = $this->compactionService->findPartitionsForCompaction(
                $organization,
                $project,
                $eventType,
                $date
            );

            if (empty($partitions)) {
                $io->success('No partitions need compaction.');

                return Command::SUCCESS;
            }

            $io->section(sprintf('Found %d partitions that would be compacted', count($partitions)));

            foreach ($partitions as $partition) {
                $io->text(sprintf('  %s (%d files)', $partition['path'], count($partition['files'])));
            }

            $io->note('This was a dry run. Run without --dry-run to actually compact files.');

            return Command::SUCCESS;
        }

        // Run actual compaction
        $summary = $this->compactionService->compact(
            $organization,
            $project,
            $eventType,
            $date
        );

        if ($summary->isEmpty()) {
            $io->success('No partitions need compaction.');

            return Command::SUCCESS;
        }

        // Show per-partition results
        $io->section(sprintf('Compacted %d partitions', $summary->partitionsCompacted));

        foreach ($summary->results as $result) {
            if ($result->success) {
                $io->text(sprintf(
                    '  %s: %d events -> %d block(s) (removed %d files)',
                    $result->partitionPath,
                    $result->eventsCount,
                    count($result->outputFiles),
                    $result->filesRemoved
                ));
            } else {
                $io->error(sprintf('  %s: Error - %s', $result->partitionPath, $result->error));
            }
        }

        // Show summary
        $io->section('Compaction Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Partitions found', $summary->partitionsFound],
                ['Partitions compacted', $summary->partitionsCompacted],
                ['Blocks created (≤50MB each)', $summary->blocksCreated],
                ['Files removed', $summary->filesRemoved],
                ['Total events', $summary->totalEvents],
                ['Errors', $summary->errors],
            ]
        );

        if ($summary->hasErrors()) {
            $io->warning('Some partitions had errors during compaction.');

            return Command::FAILURE;
        }

        $io->success('Compaction completed successfully.');

        return Command::SUCCESS;
    }
}
