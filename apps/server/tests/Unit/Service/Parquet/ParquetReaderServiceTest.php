<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Parquet;

use App\Service\Parquet\ParquetReaderService;
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

        $this->reader = new ParquetReaderService(
            $this->tempDir,
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
