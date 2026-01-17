<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ProjectRepository;
use App\Service\Parquet\ParquetWriterService;
use Flow\Parquet\Reader;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/**
 * Migrate existing Parquet files from legacy path format to Hive-style partitioning.
 *
 * Legacy format: storage/parquet/{project_id}/{YYYY}/{MM}/{DD}/events_*.parquet
 * Hive format:   storage/parquet/organization_id={org}/project_id={proj}/event_type={type}/dt={YYYY-MM-DD}/events_*.parquet
 */
#[AsCommand(
    name: 'app:parquet:migrate-to-hive',
    description: 'Migrate Parquet files from legacy path format to Hive-style partitioning',
)]
class MigrateParquetToHiveCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/storage/parquet')]
        private readonly string $storagePath,
        private readonly ProjectRepository $projectRepository,
        private readonly ParquetWriterService $parquetWriter,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate migration without making changes')
            ->addOption('delete-old', null, InputOption::VALUE_NONE, 'Delete old files after successful migration')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Migrate only a specific project ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $deleteOld = $input->getOption('delete-old');
        $specificProject = $input->getOption('project');

        if ($dryRun) {
            $io->note('Running in dry-run mode - no changes will be made');
        }

        // Find all legacy Parquet directories (directories that look like project IDs, not Hive-style)
        $legacyDirs = $this->findLegacyProjectDirs($specificProject);

        if (empty($legacyDirs)) {
            $io->success('No legacy Parquet files found to migrate.');

            return Command::SUCCESS;
        }

        $io->section('Found legacy directories');
        $io->listing($legacyDirs);

        $totalFiles = 0;
        $migratedFiles = 0;
        $skippedFiles = 0;
        $errorFiles = 0;

        foreach ($legacyDirs as $projectId => $projectPath) {
            $io->section("Migrating project: $projectId");

            // Look up the project to get organization ID
            $project = $this->projectRepository->findByPublicId($projectId);
            if (null === $project) {
                $io->warning("Project not found in database: $projectId - skipping");
                continue;
            }

            $organizationId = $project->getOrganization()->getPublicId()?->toRfc4122();
            if (null === $organizationId) {
                $io->warning("Project has no organization: $projectId - skipping");
                continue;
            }

            $io->text("Organization ID: $organizationId");

            // Find all Parquet files in this project
            $files = $this->findLegacyParquetFiles($projectPath);
            $io->text(sprintf('Found %d Parquet files', count($files)));

            foreach ($files as $filePath) {
                ++$totalFiles;

                try {
                    $result = $this->migrateFile($filePath, $organizationId, $projectId, $dryRun, $deleteOld);

                    if ($result['migrated']) {
                        ++$migratedFiles;
                        $io->text(sprintf('  ✓ Migrated: %s -> %s', basename($filePath), $result['newPath'] ?? 'N/A'));
                    } else {
                        ++$skippedFiles;
                        $io->text(sprintf('  - Skipped: %s (%s)', basename($filePath), $result['reason'] ?? 'unknown'));
                    }
                } catch (\Throwable $e) {
                    ++$errorFiles;
                    $io->error(sprintf('  ✗ Error: %s - %s', basename($filePath), $e->getMessage()));
                    $this->logger->error('Migration error', [
                        'file' => $filePath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $io->section('Migration Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Total files', $totalFiles],
                ['Migrated', $migratedFiles],
                ['Skipped', $skippedFiles],
                ['Errors', $errorFiles],
            ]
        );

        if ($dryRun) {
            $io->note('This was a dry run. Run without --dry-run to actually migrate files.');
        }

        if ($errorFiles > 0) {
            $io->warning('Some files had errors during migration.');

            return Command::FAILURE;
        }

        $io->success('Migration completed successfully.');

        return Command::SUCCESS;
    }

    /**
     * Find legacy project directories (not Hive-style).
     *
     * @return array<string, string> Map of project ID to directory path
     */
    private function findLegacyProjectDirs(?string $specificProject): array
    {
        if (!is_dir($this->storagePath)) {
            return [];
        }

        $dirs = [];

        foreach (new \DirectoryIterator($this->storagePath) as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }

            $dirName = $item->getFilename();

            // Skip Hive-style directories (contain '=')
            if (str_contains($dirName, '=')) {
                continue;
            }

            // If specific project requested, only include that one
            if (null !== $specificProject && $dirName !== $specificProject) {
                continue;
            }

            // This looks like a legacy project directory (UUID format)
            $dirs[$dirName] = $item->getPathname();
        }

        return $dirs;
    }

    /**
     * Find all Parquet files in a legacy project directory.
     *
     * @return array<string>
     */
    private function findLegacyParquetFiles(string $projectPath): array
    {
        $finder = new Finder();
        $finder->files()
            ->in($projectPath)
            ->name('*.parquet')
            ->sortByName();

        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }

    /**
     * Migrate a single Parquet file to Hive-style path.
     *
     * @return array{migrated: bool, newPath: ?string, reason: ?string}
     */
    private function migrateFile(
        string $filePath,
        string $organizationId,
        string $projectId,
        bool $dryRun,
        bool $deleteOld,
    ): array {
        // Read events from old file
        $reader = new Reader();
        $parquetFile = $reader->read($filePath);

        $events = [];
        foreach ($parquetFile->values() as $row) {
            $events[] = $row;
        }

        if (empty($events)) {
            return ['migrated' => false, 'newPath' => null, 'reason' => 'empty file'];
        }

        // Group events by event_type for separate partitions
        $eventsByType = [];
        foreach ($events as $event) {
            $eventType = $event['event_type'] ?? 'unknown';
            if (!isset($eventsByType[$eventType])) {
                $eventsByType[$eventType] = [];
            }
            // Add organization_id to each event
            $event['organization_id'] = $organizationId;
            $eventsByType[$eventType][] = $event;
        }

        if ($dryRun) {
            $types = implode(', ', array_keys($eventsByType));

            return ['migrated' => true, 'newPath' => "(dry-run: would create files for types: $types)", 'reason' => null];
        }

        $newPaths = [];
        foreach ($eventsByType as $eventType => $typeEvents) {
            $newPath = $this->parquetWriter->writeEvents($typeEvents);
            $newPaths[] = $newPath;
        }

        // Delete old file if requested (newPaths is guaranteed non-empty since events is non-empty)
        if ($deleteOld) {
            unlink($filePath);

            // Try to remove empty directories up the tree
            $this->removeEmptyDirectories(dirname($filePath));
        }

        return [
            'migrated' => true,
            'newPath' => count($newPaths) > 1 ? implode(', ', array_map('basename', $newPaths)) : basename($newPaths[0]),
            'reason' => null,
        ];
    }

    /**
     * Remove empty directories up the tree.
     */
    private function removeEmptyDirectories(string $path): void
    {
        while ($path !== $this->storagePath && is_dir($path)) {
            $files = scandir($path);
            if (false === $files || count($files) <= 2) { // Only . and ..
                rmdir($path);
                $path = dirname($path);
            } else {
                break;
            }
        }
    }
}
