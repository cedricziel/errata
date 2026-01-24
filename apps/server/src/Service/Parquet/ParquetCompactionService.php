<?php

declare(strict_types=1);

namespace App\Service\Parquet;

use App\Service\Storage\StorageFactory;
use Flow\ETL\Row;
use Flow\ETL\Row\Entry;
use Flow\ETL\Rows;
use Flow\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;

use function Flow\ETL\Adapter\Parquet\from_parquet;
use function Flow\ETL\Adapter\Parquet\to_parquet;
use function Flow\ETL\DSL\bool_entry;
use function Flow\ETL\DSL\data_frame;
use function Flow\ETL\DSL\float_entry;
use function Flow\ETL\DSL\from_rows;
use function Flow\ETL\DSL\int_entry;
use function Flow\ETL\DSL\null_entry;
use function Flow\ETL\DSL\str_entry;
use function Flow\Filesystem\DSL\path;

/**
 * Service for compacting Parquet files within partitions.
 *
 * Finds partitions with uncompacted event files (events_*.parquet) and merges them
 * into larger block files (block_*.parquet) for improved read performance.
 * Works with both local filesystem and S3-compatible storage.
 *
 * Uses batched processing to avoid memory issues when listing large numbers of files.
 */
final class ParquetCompactionService
{
    /**
     * Maximum size per compacted parquet file (50MB).
     */
    private const MAX_BLOCK_SIZE_BYTES = 50 * 1024 * 1024;

    /**
     * Maximum files to process in one batch to avoid memory issues.
     */
    private const MAX_FILES_PER_BATCH = 100;

    public function __construct(
        private readonly StorageFactory $storageFactory,
        private readonly FlowConfigFactory $flowConfigFactory,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Run compaction on all partitions matching the given filters.
     *
     * When filters are not specified, iterates through organizations and projects
     * in batches to avoid loading all files into memory at once.
     */
    public function compact(
        ?string $organizationId = null,
        ?string $projectId = null,
        ?string $eventType = null,
        ?string $date = null,
        bool $dryRun = false,
    ): CompactionSummary {
        $totalPartitionsFound = 0;
        $totalCompacted = 0;
        $totalFilesRemoved = 0;
        $totalBlocksCreated = 0;
        $totalEvents = 0;
        $errors = 0;
        $results = [];

        // If specific filters are provided, use direct lookup
        if (null !== $organizationId && null !== $projectId && null !== $eventType && null !== $date) {
            return $this->compactSinglePartition($organizationId, $projectId, $eventType, $date, $dryRun);
        }

        // Otherwise, iterate in batches to avoid memory issues
        foreach ($this->iteratePartitions($organizationId, $projectId, $eventType, $date) as $partition) {
            ++$totalPartitionsFound;

            if ($dryRun) {
                continue;
            }

            $result = $this->compactPartition($partition['path'], $partition['files']);
            $results[] = $result;

            if ($result->success) {
                ++$totalCompacted;
                $totalFilesRemoved += $result->filesRemoved;
                $totalBlocksCreated += count($result->outputFiles);
                $totalEvents += $result->eventsCount;
            } else {
                ++$errors;
            }
        }

        return new CompactionSummary(
            $totalPartitionsFound,
            $totalCompacted,
            $totalBlocksCreated,
            $totalFilesRemoved,
            $totalEvents,
            $errors,
            $results
        );
    }

    /**
     * Iterate through partitions in a memory-efficient way.
     *
     * Lists directories level by level (org -> project -> event_type -> date)
     * to avoid loading all file listings at once.
     *
     * @return \Generator<array{path: string, files: array<string>}>
     */
    private function iteratePartitions(
        ?string $organizationId = null,
        ?string $projectId = null,
        ?string $eventType = null,
        ?string $date = null,
    ): \Generator {
        $basePath = $this->storageFactory->getBasePath();
        $fstab = $this->storageFactory->createFilesystemTable();
        $filesystem = $fstab->for(path($basePath)->protocol());

        // Get organizations to process
        $organizations = null !== $organizationId
            ? ["organization_id={$organizationId}"]
            : $this->listDirectories($filesystem, $basePath, 'organization_id=');

        foreach ($organizations as $orgDir) {
            $orgPath = rtrim($basePath, '/').'/'.$orgDir;

            // Get projects to process
            $projects = null !== $projectId
                ? ["project_id={$projectId}"]
                : $this->listDirectories($filesystem, $orgPath, 'project_id=');

            foreach ($projects as $projDir) {
                $projPath = $orgPath.'/'.$projDir;

                // Get event types to process
                $eventTypes = null !== $eventType
                    ? ["event_type={$eventType}"]
                    : $this->listDirectories($filesystem, $projPath, 'event_type=');

                foreach ($eventTypes as $typeDir) {
                    $typePath = $projPath.'/'.$typeDir;

                    // Get dates to process
                    $dates = null !== $date
                        ? ["dt={$date}"]
                        : $this->listDirectories($filesystem, $typePath, 'dt=');

                    foreach ($dates as $dateDir) {
                        $partitionPath = $typePath.'/'.$dateDir;

                        // List files in this specific partition
                        $files = $this->listPartitionFiles($filesystem, $partitionPath);

                        if (!empty($files)) {
                            yield [
                                'path' => $partitionPath,
                                'files' => $files,
                            ];
                        }
                    }
                }
            }
        }
    }

    /**
     * List directories matching a prefix at a given path.
     *
     * @return array<string>
     */
    private function listDirectories(Filesystem $filesystem, string $parentPath, string $prefix): array
    {
        $dirs = [];

        try {
            // For local filesystem, use native PHP glob for reliable directory listing
            if (!$this->storageFactory->isS3Storage()) {
                $pattern = rtrim($parentPath, '/').'/'.$prefix.'*';
                $matches = glob($pattern, GLOB_ONLYDIR);
                if (false !== $matches) {
                    foreach ($matches as $match) {
                        $dirs[] = basename($match);
                    }
                }

                return array_unique($dirs);
            }

            // For S3, use filesystem list with prefix filtering
            // S3 doesn't support glob patterns, so we list with prefix and filter
            foreach ($filesystem->list(path($parentPath.'/')) as $fileStatus) {
                $name = basename($fileStatus->path->path());
                if (str_starts_with($name, $prefix)) {
                    $dirs[] = $name;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Failed to list directories', [
                'path' => $parentPath,
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);
        }

        return array_unique($dirs);
    }

    /**
     * List parquet files in a specific partition that need compaction.
     *
     * @return array<string>
     */
    private function listPartitionFiles(Filesystem $filesystem, string $partitionPath): array
    {
        $files = [];

        try {
            // For local filesystem, use native PHP glob
            if (!$this->storageFactory->isS3Storage()) {
                $pattern = rtrim($partitionPath, '/').'/events_*.parquet';
                $matches = glob($pattern);
                if (false !== $matches) {
                    foreach ($matches as $match) {
                        $files[] = $match;
                        if (count($files) >= self::MAX_FILES_PER_BATCH) {
                            break;
                        }
                    }
                }

                sort($files);

                return $files;
            }

            // For S3, list all files in directory and filter
            foreach ($filesystem->list(path($partitionPath.'/')) as $fileStatus) {
                $fileName = basename($fileStatus->path->path());

                // Only include uncompacted event files (not block_*.parquet)
                if (str_starts_with($fileName, 'events_') && str_ends_with($fileName, '.parquet')) {
                    $files[] = $fileStatus->path->path();
                }

                // Limit files per batch to avoid memory issues
                if (count($files) >= self::MAX_FILES_PER_BATCH) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Failed to list partition files', [
                'partition' => $partitionPath,
                'error' => $e->getMessage(),
            ]);
        }

        sort($files);

        return $files;
    }

    /**
     * Compact a single fully-specified partition.
     */
    private function compactSinglePartition(
        string $organizationId,
        string $projectId,
        string $eventType,
        string $date,
        bool $dryRun,
    ): CompactionSummary {
        $basePath = $this->storageFactory->getBasePath();
        $fstab = $this->storageFactory->createFilesystemTable();
        $filesystem = $fstab->for(path($basePath)->protocol());

        $partitionPath = sprintf(
            '%s/organization_id=%s/project_id=%s/event_type=%s/dt=%s',
            rtrim($basePath, '/'),
            $organizationId,
            $projectId,
            $eventType,
            $date
        );

        $files = $this->listPartitionFiles($filesystem, $partitionPath);

        if (empty($files)) {
            return new CompactionSummary(0, 0, 0, 0, 0, 0);
        }

        if ($dryRun) {
            return new CompactionSummary(1, 0, 0, 0, 0, 0);
        }

        $result = $this->compactPartition($partitionPath, $files);

        return new CompactionSummary(
            1,
            $result->success ? 1 : 0,
            count($result->outputFiles),
            $result->filesRemoved,
            $result->eventsCount,
            $result->success ? 0 : 1,
            [$result]
        );
    }

    /**
     * Find partitions that have uncompacted event files.
     *
     * @deprecated Use iteratePartitions() for memory-efficient processing
     *
     * @return array<array{path: string, files: array<string>}>
     */
    public function findPartitionsForCompaction(
        ?string $organizationId = null,
        ?string $projectId = null,
        ?string $eventType = null,
        ?string $date = null,
    ): array {
        $partitions = [];

        foreach ($this->iteratePartitions($organizationId, $projectId, $eventType, $date) as $partition) {
            $partitions[] = $partition;
        }

        return $partitions;
    }

    /**
     * Compact multiple Parquet files in a partition into blocks of max 50MB each.
     *
     * @param array<string> $files
     */
    public function compactPartition(string $partitionPath, array $files): CompactionResult
    {
        $lockKey = 'parquet_compact_'.md5($partitionPath);
        $lock = $this->lockFactory->createLock($lockKey, 300);

        if (!$lock->acquire()) {
            return CompactionResult::failure(
                $partitionPath,
                'Could not acquire lock for partition'
            );
        }

        try {
            $config = $this->flowConfigFactory->createConfig();
            $fstab = $this->storageFactory->createFilesystemTable();
            $filesystem = $fstab->for(path($partitionPath)->protocol());

            // Read all events from all files
            $allEvents = [];

            foreach ($files as $file) {
                $df = data_frame($config)
                    ->read(from_parquet($file))
                    ->fetch();

                foreach ($df as $row) {
                    $allEvents[] = $row->toArray();
                }
            }

            if (empty($allEvents)) {
                $filesRemoved = $this->deleteFiles($filesystem, $files);

                return CompactionResult::success($partitionPath, [], $filesRemoved, 0);
            }

            // Estimate rows per block and split into chunks
            $rowsPerBlock = $this->estimateRowsPerBlock($allEvents);
            $chunks = array_chunk($allEvents, $rowsPerBlock);

            $outputFiles = [];

            foreach ($chunks as $chunkIndex => $chunk) {
                $outputFile = $this->writeChunk($partitionPath, $chunk, $config, $chunkIndex);
                $outputFiles[] = $outputFile;
            }

            // Delete original files only after all chunks are written successfully
            $filesRemoved = $this->deleteFiles($filesystem, $files);

            $this->logger->info('Compacted partition into blocks', [
                'partition' => $partitionPath,
                'files_removed' => $filesRemoved,
                'events_count' => count($allEvents),
                'blocks_created' => count($outputFiles),
                'output_files' => array_map('basename', $outputFiles),
            ]);

            return CompactionResult::success(
                $partitionPath,
                $outputFiles,
                $filesRemoved,
                count($allEvents)
            );
        } catch (\Throwable $e) {
            $this->logger->error('Compaction error', [
                'partition' => $partitionPath,
                'error' => $e->getMessage(),
            ]);

            return CompactionResult::failure($partitionPath, $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * Get the current storage type.
     */
    public function getStorageType(): string
    {
        return $this->storageFactory->getStorageType();
    }

    /**
     * @param array<string> $files
     */
    private function deleteFiles(Filesystem $filesystem, array $files): int
    {
        $deleted = 0;

        foreach ($files as $file) {
            try {
                if ($filesystem->rm(path($file))) {
                    ++$deleted;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to delete file', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $deleted;
    }

    /**
     * @param array<array<string, mixed>> $events
     */
    private function estimateRowsPerBlock(array $events): int
    {
        $sampleSize = min(100, count($events));
        $sample = array_slice($events, 0, $sampleSize);

        $totalSize = 0;
        foreach ($sample as $event) {
            $totalSize += strlen((string) json_encode($event));
        }

        $avgRowSize = $totalSize / $sampleSize;
        $compressedRowSize = $avgRowSize / 3;
        $rowsPerBlock = (int) (self::MAX_BLOCK_SIZE_BYTES / max(1, $compressedRowSize));

        return max(1000, min(1_000_000, $rowsPerBlock));
    }

    /**
     * @param array<array<string, mixed>> $events
     */
    private function writeChunk(string $partitionPath, array $events, \Flow\ETL\Config $config, int $chunkIndex): string
    {
        $rows = [];
        foreach ($events as $event) {
            $rows[] = $this->eventToRow($event);
        }

        $now = new \DateTimeImmutable();
        $finalPath = sprintf(
            '%s/block_%s_%02d_%s.parquet',
            $partitionPath,
            $now->format('His'),
            $chunkIndex,
            Uuid::v7()->toRfc4122()
        );

        data_frame($config)
            ->read(from_rows(new Rows(...$rows)))
            ->write(to_parquet($finalPath))
            ->run();

        return $finalPath;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function eventToRow(array $event): Row
    {
        $entries = [];

        foreach ($event as $key => $value) {
            if (in_array($key, ['tags', 'context', 'breadcrumbs', 'stack_trace'], true) && is_array($value)) {
                $value = json_encode($value);
            }

            $entries[] = $this->createEntry($key, $value);
        }

        return Row::create(...$entries);
    }

    private function createEntry(string $key, mixed $value): Entry
    {
        if (null === $value) {
            return null_entry($key);
        }

        if (is_int($value)) {
            return int_entry($key, $value);
        }

        if (is_float($value)) {
            return float_entry($key, $value);
        }

        if (is_bool($value)) {
            return bool_entry($key, $value);
        }

        if (is_string($value)) {
            return str_entry($key, $value);
        }

        if (is_array($value)) {
            return str_entry($key, json_encode($value) ?: '[]');
        }

        return str_entry($key, (string) $value);
    }
}
