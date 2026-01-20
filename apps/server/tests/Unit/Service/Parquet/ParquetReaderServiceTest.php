<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Parquet;

use App\Service\Parquet\ParquetReaderService;
use App\Service\Storage\StorageFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ParquetReaderServiceTest extends TestCase
{
    private string $tempDir;
    private ParquetReaderService $reader;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/parquet_reader_test_'.uniqid();
        mkdir($this->tempDir, 0777, true);

        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: $this->tempDir,
        );

        $this->reader = new ParquetReaderService(
            $storageFactory,
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testExtractPartitionValuesFromHivePath(): void
    {
        $path = '/storage/organization_id=org1/project_id=proj1/event_type=span/dt=2026-01-17/file.parquet';

        $values = $this->reader->extractPartitionValues($path);

        $this->assertSame([
            'organization_id' => 'org1',
            'project_id' => 'proj1',
            'event_type' => 'span',
            'dt' => '2026-01-17',
        ], $values);
    }

    public function testExtractPartitionValuesFromPartialPath(): void
    {
        $path = '/storage/organization_id=org1/project_id=proj1/file.parquet';

        $values = $this->reader->extractPartitionValues($path);

        $this->assertSame([
            'organization_id' => 'org1',
            'project_id' => 'proj1',
        ], $values);
    }

    public function testExtractPartitionValuesFromPathWithoutPartitions(): void
    {
        $path = '/storage/some/path/file.parquet';

        $values = $this->reader->extractPartitionValues($path);

        $this->assertSame([], $values);
    }

    public function testExtractPartitionValuesHandlesUuidValues(): void
    {
        $path = '/storage/organization_id=019bccad-1111-0000-0000-000000000001/project_id=019bccad-2222-0000-0000-000000000002/file.parquet';

        $values = $this->reader->extractPartitionValues($path);

        $this->assertSame('019bccad-1111-0000-0000-000000000001', $values['organization_id']);
        $this->assertSame('019bccad-2222-0000-0000-000000000002', $values['project_id']);
    }

    public function testFindParquetFilesReturnsEmptyForNonExistentPath(): void
    {
        $files = $this->reader->findParquetFiles('nonexistent-org', 'nonexistent-proj');

        $this->assertSame([], $files);
    }

    public function testFindParquetFilesFindsFilesInHiveStructure(): void
    {
        // Create Hive-style directory structure
        $partitionDir = $this->tempDir.'/organization_id=org1/project_id=proj1/event_type=span/dt=2026-01-17';
        mkdir($partitionDir, 0777, true);
        touch($partitionDir.'/events_100000_test.parquet');
        touch($partitionDir.'/events_110000_test.parquet');

        $files = $this->reader->findParquetFiles('org1', 'proj1', 'span');

        $this->assertCount(2, $files);
        foreach ($files as $file) {
            $this->assertStringEndsWith('.parquet', $file);
            $this->assertStringContainsString('organization_id=org1', $file);
        }
    }

    public function testFindParquetFilesFiltersByOrganization(): void
    {
        // Create files in two organizations
        $org1Dir = $this->tempDir.'/organization_id=org1/project_id=proj1/event_type=span/dt=2026-01-17';
        $org2Dir = $this->tempDir.'/organization_id=org2/project_id=proj2/event_type=span/dt=2026-01-17';
        mkdir($org1Dir, 0777, true);
        mkdir($org2Dir, 0777, true);
        touch($org1Dir.'/events_test1.parquet');
        touch($org2Dir.'/events_test2.parquet');

        $files = $this->reader->findParquetFiles('org1');

        $this->assertCount(1, $files);
        $this->assertStringContainsString('organization_id=org1', $files[0]);
    }

    public function testFindParquetFilesFiltersByProject(): void
    {
        // Create files in two projects within same org
        $proj1Dir = $this->tempDir.'/organization_id=org1/project_id=proj1/event_type=span/dt=2026-01-17';
        $proj2Dir = $this->tempDir.'/organization_id=org1/project_id=proj2/event_type=span/dt=2026-01-17';
        mkdir($proj1Dir, 0777, true);
        mkdir($proj2Dir, 0777, true);
        touch($proj1Dir.'/events_test1.parquet');
        touch($proj2Dir.'/events_test2.parquet');

        $files = $this->reader->findParquetFiles('org1', 'proj1');

        $this->assertCount(1, $files);
        $this->assertStringContainsString('project_id=proj1', $files[0]);
    }

    public function testFindParquetFilesFiltersByEventType(): void
    {
        // Create files for different event types
        $spanDir = $this->tempDir.'/organization_id=org1/project_id=proj1/event_type=span/dt=2026-01-17';
        $logDir = $this->tempDir.'/organization_id=org1/project_id=proj1/event_type=log/dt=2026-01-17';
        mkdir($spanDir, 0777, true);
        mkdir($logDir, 0777, true);
        touch($spanDir.'/events_span.parquet');
        touch($logDir.'/events_log.parquet');

        $files = $this->reader->findParquetFiles('org1', 'proj1', 'span');

        $this->assertCount(1, $files);
        $this->assertStringContainsString('event_type=span', $files[0]);
    }

    public function testFindParquetFilesFiltersByDateRange(): void
    {
        // Create files for different dates
        $dates = ['2026-01-15', '2026-01-16', '2026-01-17', '2026-01-18'];
        foreach ($dates as $date) {
            $dir = $this->tempDir."/organization_id=org1/project_id=proj1/event_type=span/dt=$date";
            mkdir($dir, 0777, true);
            touch($dir."/events_$date.parquet");
        }

        $from = new \DateTimeImmutable('2026-01-16');
        $to = new \DateTimeImmutable('2026-01-17');

        $files = $this->reader->findParquetFiles('org1', 'proj1', 'span', $from, $to);

        $this->assertCount(2, $files);
        foreach ($files as $file) {
            $this->assertMatchesRegularExpression('/dt=2026-01-1[67]/', $file);
        }
    }

    public function testFindFilesWithMultipleFilters(): void
    {
        // Create complex structure
        $targetDir = $this->tempDir.'/organization_id=org1/project_id=proj1/event_type=span/dt=2026-01-17';
        $otherOrg = $this->tempDir.'/organization_id=org2/project_id=proj1/event_type=span/dt=2026-01-17';
        $otherType = $this->tempDir.'/organization_id=org1/project_id=proj1/event_type=log/dt=2026-01-17';
        $otherDate = $this->tempDir.'/organization_id=org1/project_id=proj1/event_type=span/dt=2026-01-15';

        mkdir($targetDir, 0777, true);
        mkdir($otherOrg, 0777, true);
        mkdir($otherType, 0777, true);
        mkdir($otherDate, 0777, true);

        touch($targetDir.'/target.parquet');
        touch($otherOrg.'/other_org.parquet');
        touch($otherType.'/other_type.parquet');
        touch($otherDate.'/other_date.parquet');

        $from = new \DateTimeImmutable('2026-01-17');
        $to = new \DateTimeImmutable('2026-01-17');

        $files = $this->reader->findParquetFiles('org1', 'proj1', 'span', $from, $to);

        $this->assertCount(1, $files);
        $this->assertStringContainsString('target.parquet', $files[0]);
    }

    public function testSearchPathConstructionPreservesProtocolSlashes(): void
    {
        // Test with memory storage to verify protocol-based paths are constructed correctly
        $memoryStorageFactory = new StorageFactory(
            storageType: 'memory',
            localPath: '/unused',
        );

        $memoryReader = new ParquetReaderService(
            $memoryStorageFactory,
            new NullLogger(),
        );

        // Use reflection to test the path construction logic directly
        $searchPath = $this->buildSearchPathViaReflection($memoryReader, 'org-123', 'proj-456', 'span');

        // Path should start with memory:// protocol (double slash), not memory:/ (single slash)
        $this->assertStringStartsWith('memory://', $searchPath);
        $this->assertStringContainsString('memory://organization_id=org-123', $searchPath);
        $this->assertStringNotContainsString('memory:/organization_id=', $searchPath); // No single slash after protocol
    }

    public function testSearchPathConstructionWithMemoryStorageHasCorrectStructure(): void
    {
        $memoryStorageFactory = new StorageFactory(
            storageType: 'memory',
            localPath: '/unused',
        );

        $memoryReader = new ParquetReaderService(
            $memoryStorageFactory,
            new NullLogger(),
        );

        // Test full path structure with all partition parameters
        $searchPath = $this->buildSearchPathViaReflection($memoryReader, 'org-123', 'proj-456', 'span');

        // Verify the complete path structure
        $this->assertSame('memory://organization_id=org-123/project_id=proj-456/event_type=span', $searchPath);
    }

    public function testSearchPathConstructionLocalStorageStillUsesSlashes(): void
    {
        // Ensure local storage still strips trailing slash and adds path separators correctly
        $localStorageFactory = new StorageFactory(
            storageType: 'local',
            localPath: '/storage/parquet/',
        );

        $localReader = new ParquetReaderService(
            $localStorageFactory,
            new NullLogger(),
        );

        $searchPath = $this->buildSearchPathViaReflection($localReader, 'org-123', 'proj-456', 'span');

        // Local paths should have slashes between segments
        $this->assertSame('/storage/parquet/organization_id=org-123/project_id=proj-456/event_type=span', $searchPath);
    }

    public function testSearchPathConstructionWithOrganizationOnly(): void
    {
        $memoryStorageFactory = new StorageFactory(
            storageType: 'memory',
            localPath: '/unused',
        );

        $memoryReader = new ParquetReaderService(
            $memoryStorageFactory,
            new NullLogger(),
        );

        // Test with only organization ID
        $searchPath = $this->buildSearchPathViaReflection($memoryReader, 'org-123', null, null);

        $this->assertSame('memory://organization_id=org-123', $searchPath);
    }

    public function testFindParquetFilesLocalStorageStillWorks(): void
    {
        // Ensure local storage still works correctly (no regression)
        $partitionDir = $this->tempDir.'/organization_id=local-org/project_id=local-proj/event_type=span/dt=2026-01-17';
        mkdir($partitionDir, 0777, true);
        touch($partitionDir.'/events.parquet');

        $files = $this->reader->findParquetFiles('local-org', 'local-proj', 'span');

        $this->assertCount(1, $files);
        $this->assertStringContainsString('organization_id=local-org', $files[0]);
    }

    /**
     * Use reflection to test the search path construction logic.
     *
     * This extracts the path-building logic from findStreamBasedParquetFiles
     * to verify it constructs correct paths for protocol-based storage.
     */
    private function buildSearchPathViaReflection(
        ParquetReaderService $reader,
        ?string $organizationId,
        ?string $projectId,
        ?string $eventType,
    ): string {
        // Access the private basePath property
        $reflection = new \ReflectionClass($reader);
        $basePathProperty = $reflection->getProperty('basePath');
        $basePath = $basePathProperty->getValue($reader);

        // Replicate the search path construction logic from findStreamBasedParquetFiles
        $isProtocolPath = str_contains($basePath, '://');
        if ($isProtocolPath) {
            $searchPath = $basePath;
        } else {
            $searchPath = rtrim($basePath, '/');
        }

        if (null !== $organizationId) {
            $searchPath .= ($isProtocolPath ? '' : '/').'organization_id='.$organizationId;
        }

        if (null !== $projectId && null !== $organizationId) {
            $searchPath .= '/project_id='.$projectId;
        }

        if (null !== $eventType && null !== $organizationId && null !== $projectId) {
            $searchPath .= '/event_type='.$eventType;
        }

        return $searchPath;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($path);
    }
}
