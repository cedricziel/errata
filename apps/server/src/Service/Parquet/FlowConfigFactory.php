<?php

declare(strict_types=1);

namespace App\Service\Parquet;

use App\Service\Storage\StorageFactory;
use Flow\ETL\Config;

use function Flow\ETL\DSL\config_builder;
use function Flow\Filesystem\Bridge\AsyncAWS\DSL\aws_s3_client;
use function Flow\Filesystem\Bridge\AsyncAWS\DSL\aws_s3_filesystem;

/**
 * Factory for creating Flow-PHP ETL configuration with proper filesystem mounting.
 *
 * Handles both local filesystem and S3-compatible storage configuration.
 */
final class FlowConfigFactory
{
    public function __construct(
        private readonly StorageFactory $storageFactory,
    ) {
    }

    /**
     * Create a Flow-PHP Config with appropriate filesystem mounting.
     */
    public function createConfig(): Config
    {
        $builder = config_builder();

        if ($this->storageFactory->isS3Storage()) {
            $bucket = $this->storageFactory->getS3Bucket();
            if (null === $bucket || '' === $bucket) {
                throw new \InvalidArgumentException('S3 bucket must be configured when using S3 storage type');
            }

            $builder = $builder->mount(
                aws_s3_filesystem(
                    $bucket,
                    aws_s3_client($this->storageFactory->getS3ClientConfig())
                )
            );
        }

        return $builder->build();
    }

    /**
     * Build a glob pattern for reading Parquet files with partition filtering.
     *
     * @return string Glob pattern like "base/organization_id=star/project_id=star/event_type=star/dt=star/star.parquet"
     */
    public function buildGlobPattern(
        ?string $organizationId = null,
        ?string $projectId = null,
        ?string $eventType = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): string {
        $base = $this->storageFactory->getBasePath();

        // Ensure proper path separator
        if (!str_ends_with($base, '/') && !str_contains($base, '://')) {
            $base .= '/';
        }

        // Build partition path segments - use wildcards for unspecified partitions
        $orgSegment = $organizationId ? "organization_id={$organizationId}" : 'organization_id=*';
        $projSegment = $projectId ? "project_id={$projectId}" : 'project_id=*';
        $typeSegment = $eventType ? "event_type={$eventType}" : 'event_type=*';

        // For date partitions, use wildcards or specific dates
        $dateSegment = $this->buildDateSegment($from, $to);

        return "{$base}{$orgSegment}/{$projSegment}/{$typeSegment}/{$dateSegment}/*.parquet";
    }

    /**
     * Build the date segment of the glob pattern.
     */
    private function buildDateSegment(?\DateTimeInterface $from, ?\DateTimeInterface $to): string
    {
        // If no date range specified, match all dates
        if (null === $from && null === $to) {
            return 'dt=*';
        }

        // If both dates are the same day, use exact match
        if (null !== $from && null !== $to) {
            $fromDate = $from->format('Y-m-d');
            $toDate = $to->format('Y-m-d');

            if ($fromDate === $toDate) {
                return "dt={$fromDate}";
            }
        }

        // For date ranges, we use wildcard and filter later
        // (Flow-PHP doesn't support date range globs natively)
        return 'dt=*';
    }

    /**
     * Get glob patterns for each date in the range.
     *
     * Instead of using dt=* (which scans all partitions), this method enumerates
     * each date in the range to enable partition pruning.
     *
     * @return array<string> Array of glob patterns, one per date
     */
    public function buildGlobPatternsForDateRange(
        ?string $organizationId = null,
        ?string $projectId = null,
        ?string $eventType = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        $base = $this->storageFactory->getBasePath();
        if (!str_ends_with($base, '/') && !str_contains($base, '://')) {
            $base .= '/';
        }

        $orgSegment = $organizationId ? "organization_id={$organizationId}" : 'organization_id=*';
        $projSegment = $projectId ? "project_id={$projectId}" : 'project_id=*';
        $typeSegment = $eventType ? "event_type={$eventType}" : 'event_type=*';

        // If no dates, return single wildcard pattern (full scan intended)
        if (null === $from && null === $to) {
            return ["{$base}{$orgSegment}/{$projSegment}/{$typeSegment}/dt=*/*.parquet"];
        }

        // Handle case where only one date is provided
        $startDate = $from ?? $to;
        $endDate = $to ?? new \DateTimeImmutable('now');

        // Enumerate dates in range
        $patterns = [];
        $current = \DateTimeImmutable::createFromInterface($startDate)->setTime(0, 0, 0);
        $end = \DateTimeImmutable::createFromInterface($endDate)->setTime(23, 59, 59);

        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $patterns[] = "{$base}{$orgSegment}/{$projSegment}/{$typeSegment}/dt={$dateStr}/*.parquet";
            $current = $current->modify('+1 day');
        }

        return $patterns;
    }
}
