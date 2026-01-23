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
 */
final class ParquetCompactionService
{
    /**
     * Maximum size per compacted parquet file (50MB).
     */
    private const MAX_BLOCK_SIZE_BYTES = 50 * 1024 * 1024;

    public function __construct(
        private readonly StorageFactory $storageFactory,
        private readonly FlowConfigFactory $flowConfigFactory,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Run compaction on all partitions matching the given filters.
     */
    public function compact(
        ?string $organizationId = null,
        ?string $projectId = null,
        ?string $eventType = null,
        ?string $date = null,
        bool $dryRun = false,
    ): CompactionSummary {
        $partitions = $this->findPartitionsForCompaction(
            $organizationId,
            $projectId,
            $eventType,
            $date
        );

        if (empty($partitions)) {
            return new CompactionSummary(0, 0, 0, 0, 0, 0);
        }

        $totalCompacted = 0;
        $totalFilesRemoved = 0;
        $totalBlocksCreated = 0;
        $totalEvents = 0;
        $errors = 0;
        $results = [];

        foreach ($partitions as $partition) {
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
            count($partitions),
            $totalCompacted,
            $totalBlocksCreated,
            $totalFilesRemoved,
            $totalEvents,
            $errors,
            $results
        );
    }

    /**
     * Find partitions that have uncompacted event files.
     *
     * @return array<array{path: string, files: array<string>}>
     */
    public function findPartitionsForCompaction(
        ?string $organizationId = null,
        ?string $projectId = null,
        ?string $eventType = null,
        ?string $date = null,
    ): array {
        $basePath = $this->storageFactory->getBasePath();
        $fstab = $this->storageFactory->createFilesystemTable();

        $globPattern = $this->buildGlobPattern($basePath, $organizationId, $projectId, $eventType, $date);

        $this->logger->debug('Searching for uncompacted files', ['pattern' => $globPattern]);

        $filesystem = $fstab->for(path($basePath)->protocol());

        $partitionFiles = [];

        try {
            /** @var \Flow\Filesystem\FileStatus $fileStatus */
            foreach ($filesystem->list(path($globPattern)) as $fileStatus) {
                $filePath = $fileStatus->path->path();
                $fileName = basename($filePath);

                // Only include uncompacted event files (not block_*.parquet)
                if (!str_starts_with($fileName, 'events_')) {
                    continue;
                }

                $partitionPath = dirname($filePath);

                if (!isset($partitionFiles[$partitionPath])) {
                    $partitionFiles[$partitionPath] = [];
                }
                $partitionFiles[$partitionPath][] = $filePath;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to list files for compaction', [
                'pattern' => $globPattern,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $partitions = [];
        foreach ($partitionFiles as $path => $files) {
            sort($files);
            $partitions[] = [
                'path' => $path,
                'files' => $files,
            ];
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

    private function buildGlobPattern(
        string $basePath,
        ?string $organizationId,
        ?string $projectId,
        ?string $eventType,
        ?string $date,
    ): string {
        if (!str_ends_with($basePath, '/') && !str_contains($basePath, '://')) {
            $basePath .= '/';
        }

        $orgPart = $organizationId ? "organization_id={$organizationId}" : 'organization_id=*';
        $projPart = $projectId ? "project_id={$projectId}" : 'project_id=*';
        $typePart = $eventType ? "event_type={$eventType}" : 'event_type=*';
        $datePart = $date ? "dt={$date}" : 'dt=*';

        return "{$basePath}{$orgPart}/{$projPart}/{$typePart}/{$datePart}/*.parquet";
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
