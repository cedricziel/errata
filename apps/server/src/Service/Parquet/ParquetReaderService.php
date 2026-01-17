<?php

declare(strict_types=1);

namespace App\Service\Parquet;

use Flow\Parquet\Reader;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/**
 * Service for reading wide events from Parquet files.
 */
class ParquetReaderService
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/storage/parquet')]
        private readonly string $storagePath,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Read all events for a project within a date range.
     *
     * @param array<string, mixed> $filters
     *
     * @return \Generator<array<string, mixed>>
     */
    public function readEvents(
        string $projectId,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        array $filters = [],
    ): \Generator {
        $files = $this->findParquetFiles($projectId, $from, $to);

        foreach ($files as $file) {
            yield from $this->readFile($file, $filters);
        }
    }

    /**
     * Read events from a specific Parquet file.
     *
     * @param array<string, mixed> $filters
     *
     * @return \Generator<array<string, mixed>>
     */
    public function readFile(string $filePath, array $filters = []): \Generator
    {
        if (!file_exists($filePath)) {
            $this->logger->warning('Parquet file not found', ['file' => $filePath]);

            return;
        }

        try {
            $reader = new Reader();
            $parquetFile = $reader->read($filePath);

            foreach ($parquetFile->values() as $row) {
                $event = $this->rowToEvent($row);

                if ($this->matchesFilters($event, $filters)) {
                    yield $event;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to read Parquet file', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Count events for a project.
     *
     * @param array<string, mixed> $filters
     */
    public function countEvents(
        string $projectId,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        array $filters = [],
    ): int {
        $count = 0;

        foreach ($this->readEvents($projectId, $from, $to, $filters) as $event) {
            ++$count;
        }

        return $count;
    }

    /**
     * Get event statistics for a project.
     *
     * @return array<string, mixed>
     */
    public function getEventStats(
        string $projectId,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        $stats = [
            'total' => 0,
            'by_type' => [],
            'by_severity' => [],
            'by_day' => [],
        ];

        foreach ($this->readEvents($projectId, $from, $to) as $event) {
            ++$stats['total'];

            // Count by type
            $type = $event['event_type'] ?? 'unknown';
            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;

            // Count by severity
            if (!empty($event['severity'])) {
                $severity = $event['severity'];
                $stats['by_severity'][$severity] = ($stats['by_severity'][$severity] ?? 0) + 1;
            }

            // Count by day
            if (!empty($event['timestamp'])) {
                $date = date('Y-m-d', (int) ($event['timestamp'] / 1000));
                $stats['by_day'][$date] = ($stats['by_day'][$date] ?? 0) + 1;
            }
        }

        ksort($stats['by_day']);

        return $stats;
    }

    /**
     * Get events for a specific issue (by fingerprint).
     *
     * @return array<array<string, mixed>>
     */
    public function getEventsByFingerprint(
        string $projectId,
        string $fingerprint,
        int $limit = 50,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        $events = [];
        $filters = ['fingerprint' => $fingerprint];

        foreach ($this->readEvents($projectId, $from, $to, $filters) as $event) {
            $events[] = $event;

            if (count($events) >= $limit) {
                break;
            }
        }

        // Sort by timestamp descending
        usort($events, fn ($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

        return $events;
    }

    /**
     * Find Parquet files for a project within a date range.
     *
     * @return array<string>
     */
    public function findParquetFiles(
        string $projectId,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        $basePath = $this->storagePath.'/'.$projectId;

        if (!is_dir($basePath)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()
            ->in($basePath)
            ->name('*.parquet')
            ->sortByName();

        // Filter by date if specified
        if (null !== $from || null !== $to) {
            $finder->filter(function (\SplFileInfo $file) use ($from, $to) {
                return $this->fileMatchesDateRange($file->getPathname(), $from, $to);
            });
        }

        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }

    /**
     * Check if a file's path matches the date range.
     */
    private function fileMatchesDateRange(
        string $filePath,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to,
    ): bool {
        // Extract date from path like: /project_id/2024/01/15/events_*.parquet
        if (preg_match('#/(\d{4})/(\d{2})/(\d{2})/#', $filePath, $matches)) {
            $fileDate = new \DateTimeImmutable("{$matches[1]}-{$matches[2]}-{$matches[3]}");

            if (null !== $from) {
                $fromDate = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0, 0);
                if ($fileDate < $fromDate) {
                    return false;
                }
            }

            if (null !== $to) {
                $toDate = \DateTimeImmutable::createFromInterface($to)->setTime(23, 59, 59);
                if ($fileDate > $toDate) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Convert a Parquet row to an event array.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function rowToEvent(array $row): array
    {
        $event = $row;

        // Decode JSON fields
        if (isset($event['tags']) && is_string($event['tags'])) {
            $event['tags'] = json_decode($event['tags'], true) ?? [];
        }
        if (isset($event['context']) && is_string($event['context'])) {
            $event['context'] = json_decode($event['context'], true) ?? [];
        }
        if (isset($event['breadcrumbs']) && is_string($event['breadcrumbs'])) {
            $event['breadcrumbs'] = json_decode($event['breadcrumbs'], true) ?? [];
        }
        if (isset($event['stack_trace']) && is_string($event['stack_trace'])) {
            $event['stack_trace'] = json_decode($event['stack_trace'], true) ?? [];
        }

        return $event;
    }

    /**
     * Check if an event matches the given filters.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $filters
     */
    private function matchesFilters(array $event, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if (!isset($event[$key])) {
                return false;
            }

            if ($event[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
