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

        // For date ranges, we use wildcard and filter in DataFrame
        $this->assertSame(
            '/storage/parquet/organization_id=org-123/project_id=proj-456/event_type=span/dt=*/*.parquet',
            $pattern
        );
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
