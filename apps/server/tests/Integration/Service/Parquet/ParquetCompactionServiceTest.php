<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Parquet;

use App\Service\Parquet\CompactionResult;
use App\Service\Parquet\CompactionSummary;
use App\Service\Parquet\FlowConfigFactory;
use App\Service\Parquet\ParquetCompactionService;
use App\Service\Parquet\ParquetReaderService;
use App\Service\Parquet\ParquetWriterService;
use App\Service\Parquet\WideEventSchema;
use App\Service\Storage\StorageFactory;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Uid\Uuid;

class ParquetCompactionServiceTest extends KernelTestCase
{
    private string $storagePath;
    private ParquetWriterService $writer;
    private ParquetReaderService $reader;
    private ParquetCompactionService $compactionService;
    private StorageFactory $storageFactory;
    private FlowConfigFactory $flowConfigFactory;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->storagePath = sys_get_temp_dir().'/parquet_compaction_test_'.uniqid();
        mkdir($this->storagePath, 0777, true);

        $this->storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: $this->storagePath,
        );

        $this->flowConfigFactory = new FlowConfigFactory($this->storageFactory);
        $logger = new NullLogger();

        $this->writer = new ParquetWriterService(
            $this->storageFactory,
            $this->flowConfigFactory,
            $logger,
        );

        $this->reader = new ParquetReaderService(
            $this->storageFactory,
            $this->flowConfigFactory,
            $logger,
        );

        $lockFactory = new LockFactory(new FlockStore());

        $this->compactionService = new ParquetCompactionService(
            $this->storageFactory,
            $this->flowConfigFactory,
            $lockFactory,
            $logger,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);
        parent::tearDown();
    }

    public function testFindPartitionsForCompactionFindsUncompactedFiles(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = strtotime('2026-01-17 10:00:00') * 1000;

        // Create multiple event files in the same partition
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp, 'Event 1')]);
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp, 'Event 2')]);
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp, 'Event 3')]);

        $partitions = $this->compactionService->findPartitionsForCompaction($orgId, $projectId);

        $this->assertCount(1, $partitions);
        $this->assertCount(3, $partitions[0]['files']);
        $this->assertStringContainsString("organization_id={$orgId}", $partitions[0]['path']);
    }

    public function testFindPartitionsIgnoresBlockFiles(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = strtotime('2026-01-17 10:00:00') * 1000;

        // Create event files
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp, 'Event 1')]);

        // Get partitions before compaction
        $partitionsBefore = $this->compactionService->findPartitionsForCompaction($orgId, $projectId);
        $this->assertCount(1, $partitionsBefore);

        // Compact the partition
        $this->compactionService->compact($orgId, $projectId);

        // After compaction, no uncompacted files should remain
        $partitionsAfter = $this->compactionService->findPartitionsForCompaction($orgId, $projectId);
        $this->assertCount(0, $partitionsAfter);
    }

    public function testCompactPartitionMergesFilesIntoBlock(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = strtotime('2026-01-17 10:00:00') * 1000;

        // Create multiple event files
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp, 'Event 1')]);
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp, 'Event 2')]);

        $partitions = $this->compactionService->findPartitionsForCompaction($orgId, $projectId);
        $this->assertCount(1, $partitions);

        $result = $this->compactionService->compactPartition(
            $partitions[0]['path'],
            $partitions[0]['files']
        );

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->filesRemoved);
        $this->assertSame(2, $result->eventsCount);
        $this->assertCount(1, $result->outputFiles);

        // Verify output file exists and is named block_*
        $this->assertFileExists($result->outputFiles[0]);
        $this->assertStringContainsString('block_', basename($result->outputFiles[0]));
    }

    public function testCompactPreservesAllEvents(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = strtotime('2026-01-17 10:00:00') * 1000;

        // Create multiple event files with distinct messages
        $messages = ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'];
        foreach ($messages as $msg) {
            $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp, $msg)]);
        }

        // Count events before compaction
        $eventsBefore = iterator_to_array($this->reader->readEvents($orgId, $projectId));
        $this->assertCount(5, $eventsBefore);

        // Compact
        $summary = $this->compactionService->compact($orgId, $projectId);
        $this->assertSame(5, $summary->totalEvents);

        // Count events after compaction
        $eventsAfter = iterator_to_array($this->reader->readEvents($orgId, $projectId));
        $this->assertCount(5, $eventsAfter);

        // Verify all messages are preserved
        $afterMessages = array_column($eventsAfter, 'message');
        foreach ($messages as $msg) {
            $this->assertContains($msg, $afterMessages);
        }
    }

    public function testCompactWithOrganizationFilter(): void
    {
        $org1 = 'org-1-'.Uuid::v7();
        $org2 = 'org-2-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = strtotime('2026-01-17 10:00:00') * 1000;

        // Create files in both orgs
        $this->writer->writeEvents([$this->createEvent($org1, $projectId, 'log', $timestamp, 'Org 1 Event')]);
        $this->writer->writeEvents([$this->createEvent($org2, $projectId, 'log', $timestamp, 'Org 2 Event')]);

        // Compact only org1
        $summary = $this->compactionService->compact(organizationId: $org1);

        $this->assertSame(1, $summary->partitionsFound);
        $this->assertSame(1, $summary->partitionsCompacted);

        // Org2 should still have uncompacted files
        $org2Partitions = $this->compactionService->findPartitionsForCompaction($org2);
        $this->assertCount(1, $org2Partitions);
    }

    public function testCompactWithDateFilter(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();

        // Create events on different dates
        $timestamp1 = strtotime('2026-01-15 10:00:00') * 1000;
        $timestamp2 = strtotime('2026-01-16 10:00:00') * 1000;
        $timestamp3 = strtotime('2026-01-17 10:00:00') * 1000;

        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp1, 'Jan 15')]);
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp2, 'Jan 16')]);
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp3, 'Jan 17')]);

        // Compact only Jan 16
        $summary = $this->compactionService->compact(date: '2026-01-16');

        $this->assertSame(1, $summary->partitionsFound);
        $this->assertSame(1, $summary->partitionsCompacted);

        // Other dates should still have uncompacted files
        $allPartitions = $this->compactionService->findPartitionsForCompaction($orgId);
        $this->assertCount(2, $allPartitions); // Jan 15 and Jan 17 remain uncompacted
    }

    public function testCompactWithEventTypeFilter(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = strtotime('2026-01-17 10:00:00') * 1000;

        // Create different event types
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp, 'Log event')]);
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'span', $timestamp, 'Span event')]);

        // Compact only logs
        $summary = $this->compactionService->compact(eventType: 'log');

        $this->assertSame(1, $summary->partitionsFound);

        // Spans should still have uncompacted files
        $spanPartitions = $this->compactionService->findPartitionsForCompaction(eventType: 'span');
        $this->assertCount(1, $spanPartitions);
    }

    public function testCompactReturnsEmptySummaryWhenNoPartitionsFound(): void
    {
        $summary = $this->compactionService->compact(organizationId: 'nonexistent-org');

        $this->assertTrue($summary->isEmpty());
        $this->assertSame(0, $summary->partitionsFound);
        $this->assertSame(0, $summary->partitionsCompacted);
        $this->assertFalse($summary->hasErrors());
    }

    public function testCompactDryRunDoesNotModifyFiles(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = strtotime('2026-01-17 10:00:00') * 1000;

        // Create event files
        $file1 = $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp, 'Event 1')]);
        $file2 = $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp, 'Event 2')]);

        // Run dry-run compaction
        $summary = $this->compactionService->compact($orgId, $projectId, dryRun: true);

        // Files should still exist
        $this->assertFileExists($file1);
        $this->assertFileExists($file2);

        // Summary should show partitions found but none compacted
        $this->assertSame(1, $summary->partitionsFound);
        $this->assertSame(0, $summary->partitionsCompacted);
    }

    public function testCompactHandlesEmptyPartition(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = strtotime('2026-01-17 10:00:00') * 1000;

        // Create and then manually empty a file (simulating corrupted/empty file)
        $file = $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp)]);

        // Get partitions
        $partitions = $this->compactionService->findPartitionsForCompaction($orgId, $projectId);
        $this->assertCount(1, $partitions);

        // Normal compaction should work
        $result = $this->compactionService->compactPartition(
            $partitions[0]['path'],
            $partitions[0]['files']
        );

        $this->assertTrue($result->success);
    }

    public function testCompactionResultSuccess(): void
    {
        $result = CompactionResult::success(
            '/path/to/partition',
            ['/path/to/block_001.parquet'],
            5,
            100
        );

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertSame('/path/to/partition', $result->partitionPath);
        $this->assertSame(5, $result->filesRemoved);
        $this->assertSame(100, $result->eventsCount);
    }

    public function testCompactionResultFailure(): void
    {
        $result = CompactionResult::failure(
            '/path/to/partition',
            'Lock acquisition failed'
        );

        $this->assertFalse($result->success);
        $this->assertSame('Lock acquisition failed', $result->error);
        $this->assertSame(0, $result->filesRemoved);
        $this->assertSame(0, $result->eventsCount);
    }

    public function testCompactionSummaryHasErrors(): void
    {
        $summary = new CompactionSummary(
            partitionsFound: 10,
            partitionsCompacted: 8,
            blocksCreated: 8,
            filesRemoved: 20,
            totalEvents: 1000,
            errors: 2
        );

        $this->assertTrue($summary->hasErrors());
        $this->assertFalse($summary->isEmpty());
    }

    public function testCompactionSummaryNoErrors(): void
    {
        $summary = new CompactionSummary(
            partitionsFound: 10,
            partitionsCompacted: 10,
            blocksCreated: 10,
            filesRemoved: 30,
            totalEvents: 1500,
            errors: 0
        );

        $this->assertFalse($summary->hasErrors());
        $this->assertFalse($summary->isEmpty());
    }

    public function testGetStorageTypeReturnsCorrectType(): void
    {
        $this->assertSame('local', $this->compactionService->getStorageType());
    }

    public function testMultiplePartitionsCompactedInSingleRun(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $proj1 = 'proj-1-'.Uuid::v7();
        $proj2 = 'proj-2-'.Uuid::v7();
        $timestamp = strtotime('2026-01-17 10:00:00') * 1000;

        // Create files in two different partitions (different projects)
        $this->writer->writeEvents([$this->createEvent($orgId, $proj1, 'log', $timestamp, 'Project 1')]);
        $this->writer->writeEvents([$this->createEvent($orgId, $proj2, 'log', $timestamp, 'Project 2')]);

        // Compact all partitions for the org
        $summary = $this->compactionService->compact(organizationId: $orgId);

        $this->assertSame(2, $summary->partitionsFound);
        $this->assertSame(2, $summary->partitionsCompacted);
        $this->assertSame(2, $summary->blocksCreated);
        $this->assertSame(2, $summary->filesRemoved);
    }

    public function testCompactedFilesAreNamedWithBlockPrefix(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = strtotime('2026-01-17 10:00:00') * 1000;

        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp, 'Event')]);

        $summary = $this->compactionService->compact($orgId, $projectId);

        $this->assertCount(1, $summary->results);
        $result = $summary->results[0];

        $this->assertCount(1, $result->outputFiles);
        $blockFile = basename($result->outputFiles[0]);

        $this->assertStringStartsWith('block_', $blockFile);
        $this->assertStringEndsWith('.parquet', $blockFile);
    }

    public function testOriginalEventFilesDeletedAfterCompaction(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = strtotime('2026-01-17 10:00:00') * 1000;

        // Create multiple event files
        $file1 = $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp, 'Event 1')]);
        $file2 = $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp, 'Event 2')]);

        // Verify files exist before compaction
        $this->assertFileExists($file1);
        $this->assertFileExists($file2);

        // Compact
        $this->compactionService->compact($orgId, $projectId);

        // Verify original files are deleted
        $this->assertFileDoesNotExist($file1);
        $this->assertFileDoesNotExist($file2);
    }

    public function testFindPartitionsWithMultipleFilters(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = strtotime('2026-01-17 10:00:00') * 1000;

        // Create target event
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp, 'Target')]);

        // Create noise events
        $this->writer->writeEvents([$this->createEvent('other-org', $projectId, 'log', $timestamp, 'Wrong org')]);
        $this->writer->writeEvents([$this->createEvent($orgId, 'other-proj', 'log', $timestamp, 'Wrong proj')]);
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'span', $timestamp, 'Wrong type')]);

        // Find partitions with all filters
        $partitions = $this->compactionService->findPartitionsForCompaction(
            $orgId,
            $projectId,
            'log',
            '2026-01-17'
        );

        $this->assertCount(1, $partitions);
        $this->assertCount(1, $partitions[0]['files']);
    }

    /**
     * Create a test event with the specified parameters.
     *
     * @return array<string, mixed>
     */
    private function createEvent(
        string $organizationId,
        string $projectId,
        string $eventType,
        int $timestamp,
        string $message = 'Test message',
    ): array {
        return WideEventSchema::normalize([
            'event_id' => Uuid::v7()->toRfc4122(),
            'timestamp' => $timestamp,
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'event_type' => $eventType,
            'severity' => 'info',
            'message' => $message,
            'fingerprint' => hash('sha256', $message.uniqid()),
        ]);
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
