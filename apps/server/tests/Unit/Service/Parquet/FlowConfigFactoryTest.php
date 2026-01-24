<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Parquet;

use App\Service\Parquet\FlowConfigFactory;
use App\Service\Storage\StorageFactory;
use Flow\ETL\Config;
use PHPUnit\Framework\TestCase;

class FlowConfigFactoryTest extends TestCase
{
    public function testCreateConfigReturnsConfig(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: '/tmp/test',
        );

        $factory = new FlowConfigFactory($storageFactory);
        $config = $factory->createConfig();

        $this->assertInstanceOf(Config::class, $config);
    }

    public function testBuildGlobPatternWithAllPartitions(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: '/storage/parquet/',
        );

        $factory = new FlowConfigFactory($storageFactory);
        $pattern = $factory->buildGlobPattern('org-123', 'proj-456', 'span');

        $this->assertSame(
            '/storage/parquet/organization_id=org-123/project_id=proj-456/event_type=span/dt=*/*.parquet',
            $pattern
        );
    }

    public function testBuildGlobPatternWithWildcards(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: '/storage/parquet/',
        );

        $factory = new FlowConfigFactory($storageFactory);
        $pattern = $factory->buildGlobPattern();

        $this->assertSame(
            '/storage/parquet/organization_id=*/project_id=*/event_type=*/dt=*/*.parquet',
            $pattern
        );
    }

    public function testBuildGlobPatternWithOrganizationOnly(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: '/storage/parquet/',
        );

        $factory = new FlowConfigFactory($storageFactory);
        $pattern = $factory->buildGlobPattern('org-123');

        $this->assertSame(
            '/storage/parquet/organization_id=org-123/project_id=*/event_type=*/dt=*/*.parquet',
            $pattern
        );
    }

    public function testBuildGlobPatternWithSameDayDateRange(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: '/storage/parquet/',
        );

        $factory = new FlowConfigFactory($storageFactory);

        $from = new \DateTimeImmutable('2026-01-17');
        $to = new \DateTimeImmutable('2026-01-17');

        $pattern = $factory->buildGlobPattern('org-123', 'proj-456', 'span', $from, $to);

        $this->assertSame(
            '/storage/parquet/organization_id=org-123/project_id=proj-456/event_type=span/dt=2026-01-17/*.parquet',
            $pattern
        );
    }

    public function testBuildGlobPatternWithDateRangeUsesWildcard(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: '/storage/parquet/',
        );

        $factory = new FlowConfigFactory($storageFactory);

        $from = new \DateTimeImmutable('2026-01-15');
        $to = new \DateTimeImmutable('2026-01-17');

        $pattern = $factory->buildGlobPattern('org-123', 'proj-456', 'span', $from, $to);

        // buildGlobPattern still uses wildcard for date ranges (legacy behavior)
        $this->assertSame(
            '/storage/parquet/organization_id=org-123/project_id=proj-456/event_type=span/dt=*/*.parquet',
            $pattern
        );
    }

    public function testBuildGlobPatternsForDateRangeEnumeratesDates(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: '/storage/parquet/',
        );

        $factory = new FlowConfigFactory($storageFactory);

        $from = new \DateTimeImmutable('2024-01-15');
        $to = new \DateTimeImmutable('2024-01-17');

        $patterns = $factory->buildGlobPatternsForDateRange(from: $from, to: $to);

        $this->assertCount(3, $patterns);
        $this->assertStringContainsString('dt=2024-01-15', $patterns[0]);
        $this->assertStringContainsString('dt=2024-01-16', $patterns[1]);
        $this->assertStringContainsString('dt=2024-01-17', $patterns[2]);
    }

    public function testBuildGlobPatternsForSameDayReturnsSinglePattern(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: '/storage/parquet/',
        );

        $factory = new FlowConfigFactory($storageFactory);
        $date = new \DateTimeImmutable('2024-01-15');

        $patterns = $factory->buildGlobPatternsForDateRange(from: $date, to: $date);

        $this->assertCount(1, $patterns);
        $this->assertStringContainsString('dt=2024-01-15', $patterns[0]);
    }

    public function testBuildGlobPatternsWithNoDatesReturnsWildcard(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: '/storage/parquet/',
        );

        $factory = new FlowConfigFactory($storageFactory);

        $patterns = $factory->buildGlobPatternsForDateRange();

        $this->assertCount(1, $patterns);
        $this->assertStringContainsString('dt=*', $patterns[0]);
    }

    public function testBuildGlobPatternsWithOnlyFromUsesToday(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: '/storage/parquet/',
        );

        $factory = new FlowConfigFactory($storageFactory);
        $from = new \DateTimeImmutable('today');

        // Should enumerate from today to today (at minimum 1 pattern)
        $patterns = $factory->buildGlobPatternsForDateRange(from: $from);

        $this->assertNotEmpty($patterns);
        $this->assertStringContainsString('dt='.$from->format('Y-m-d'), $patterns[0]);
    }

    public function testBuildGlobPatternsPreservesOtherPartitions(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: '/storage/parquet/',
        );

        $factory = new FlowConfigFactory($storageFactory);
        $from = new \DateTimeImmutable('2024-01-15');
        $to = new \DateTimeImmutable('2024-01-15');

        $patterns = $factory->buildGlobPatternsForDateRange(
            organizationId: 'org-123',
            projectId: 'proj-456',
            from: $from,
            to: $to,
        );

        $this->assertCount(1, $patterns);
        $this->assertStringContainsString('organization_id=org-123', $patterns[0]);
        $this->assertStringContainsString('project_id=proj-456', $patterns[0]);
        $this->assertStringContainsString('dt=2024-01-15', $patterns[0]);
    }

    public function testBuildGlobPatternWithMemoryStorage(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'memory',
            localPath: '/unused',
        );

        $factory = new FlowConfigFactory($storageFactory);
        $pattern = $factory->buildGlobPattern('org-123', 'proj-456', 'span');

        // Memory storage uses protocol prefix
        $this->assertSame(
            'memory://organization_id=org-123/project_id=proj-456/event_type=span/dt=*/*.parquet',
            $pattern
        );
    }

    public function testBuildGlobPatternPreservesProtocolSlashes(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'memory',
            localPath: '/unused',
        );

        $factory = new FlowConfigFactory($storageFactory);
        $pattern = $factory->buildGlobPattern();

        // Should preserve memory:// (double slash)
        $this->assertStringStartsWith('memory://', $pattern);
        $this->assertStringNotContainsString('memory:/organization_id=', $pattern);
    }
}
