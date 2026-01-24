<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Parquet;

use App\Service\Parquet\FlowConfigFactory;
use App\Service\Parquet\ParquetReaderService;
use App\Service\Parquet\ParquetWriterService;
use App\Service\Parquet\WideEventSchema;
use App\Service\Storage\StorageFactory;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class HivePartitioningIntegrationTest extends KernelTestCase
{
    private string $storagePath;
    private ParquetWriterService $writer;
    private ParquetReaderService $reader;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->storagePath = sys_get_temp_dir().'/parquet_integration_test_'.uniqid();
        mkdir($this->storagePath, 0777, true);

        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: $this->storagePath,
        );

        $flowConfigFactory = new FlowConfigFactory($storageFactory);
        $logger = new NullLogger();

        // Create services with test storage path
        $this->writer = new ParquetWriterService(
            $storageFactory,
            $flowConfigFactory,
            $logger,
        );

        $this->reader = new ParquetReaderService(
            $storageFactory,
            $flowConfigFactory,
            $logger,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);
        parent::tearDown();
    }

    public function testWriteEventCreatesHiveStructure(): void
    {
        $orgId = 'test-org-'.Uuid::v7();
        $projectId = 'test-proj-'.Uuid::v7();
        $timestamp = strtotime('2026-01-17 15:00:00') * 1000;

        $events = [
            $this->createEvent($orgId, $projectId, 'span', $timestamp),
        ];

        $filePath = $this->writer->writeEvents($events);

        $this->assertFileExists($filePath);

        // Verify directory structure
        $expectedPath = sprintf(
            'organization_id=%s/project_id=%s/event_type=span/dt=2026-01-17',
            $orgId,
            $projectId
        );
        $this->assertStringContainsString($expectedPath, $filePath);
    }

    public function testReadEventsWithOrganizationFilter(): void
    {
        $org1 = 'org-1-'.Uuid::v7();
        $org2 = 'org-2-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = time() * 1000;

        // Create events in two orgs
        $this->writer->writeEvents([$this->createEvent($org1, $projectId, 'log', $timestamp, 'Org 1 event')]);
        $this->writer->writeEvents([$this->createEvent($org2, $projectId, 'log', $timestamp, 'Org 2 event')]);

        // Read only org1's events
        $events = iterator_to_array($this->reader->readEvents($org1));

        $this->assertCount(1, $events);
        $this->assertSame('Org 1 event', $events[0]['message']);
    }

    public function testReadEventsWithEventTypeFilter(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = time() * 1000;

        // Create different event types
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'span', $timestamp, 'Span event')]);
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp, 'Log event')]);

        // Read only spans
        $events = iterator_to_array($this->reader->readEvents($orgId, $projectId, 'span'));

        $this->assertCount(1, $events);
        $this->assertSame('Span event', $events[0]['message']);
    }

    public function testReadEventsWithDateRangeFilter(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();

        // Create events on different dates
        $dates = ['2026-01-15', '2026-01-16', '2026-01-17'];
        foreach ($dates as $date) {
            $timestamp = strtotime("$date 12:00:00") * 1000;
            $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp, "Event on $date")]);
        }

        // Read only middle date
        $from = new \DateTimeImmutable('2026-01-16');
        $to = new \DateTimeImmutable('2026-01-16');

        $events = iterator_to_array($this->reader->readEvents($orgId, $projectId, 'log', $from, $to));

        $this->assertCount(1, $events);
        $this->assertSame('Event on 2026-01-16', $events[0]['message']);
    }

    public function testCrossProjectQueryWithinOrganization(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $proj1 = 'proj-1-'.Uuid::v7();
        $proj2 = 'proj-2-'.Uuid::v7();
        $timestamp = time() * 1000;

        // Create events in two projects of same org
        $file1 = $this->writer->writeEvents([$this->createEvent($orgId, $proj1, 'log', $timestamp, 'Project 1 event')]);
        $file2 = $this->writer->writeEvents([$this->createEvent($orgId, $proj2, 'log', $timestamp, 'Project 2 event')]);

        // Verify both files exist
        $this->assertFileExists($file1, 'File 1 should exist');
        $this->assertFileExists($file2, 'File 2 should exist');

        // Query by org only (no project filter)
        $events = [];
        foreach ($this->reader->readEvents($orgId) as $event) {
            $events[] = $event;
        }

        $this->assertCount(2, $events);

        $messages = array_column($events, 'message');
        $this->assertContains('Project 1 event', $messages);
        $this->assertContains('Project 2 event', $messages);
    }

    public function testOrganizationIsolation(): void
    {
        $org1 = 'org-1-'.Uuid::v7();
        $org2 = 'org-2-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = time() * 1000;

        // Create events in both orgs
        $this->writer->writeEvents([$this->createEvent($org1, $projectId, 'log', $timestamp, 'Org 1 secret')]);
        $this->writer->writeEvents([$this->createEvent($org2, $projectId, 'log', $timestamp, 'Org 2 secret')]);

        // Query org1 - should not see org2's events
        $org1Events = iterator_to_array($this->reader->readEvents($org1));

        $this->assertCount(1, $org1Events);
        $this->assertSame('Org 1 secret', $org1Events[0]['message']);
        $this->assertSame($org1, $org1Events[0]['organization_id']);
    }

    public function testEventTypeSeparation(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = time() * 1000;

        $eventTypes = ['span', 'log', 'metric', 'error', 'crash'];
        foreach ($eventTypes as $type) {
            $this->writer->writeEvents([$this->createEvent($orgId, $projectId, $type, $timestamp, "Event type: $type")]);
        }

        // Each event type should be in its own partition
        foreach ($eventTypes as $type) {
            $events = iterator_to_array($this->reader->readEvents($orgId, $projectId, $type));
            $this->assertCount(1, $events, "Expected 1 event for type: $type");
            $this->assertSame("Event type: $type", $events[0]['message']);
        }
    }

    public function testOrganizationIdStoredInEventData(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = time() * 1000;

        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $timestamp)]);

        $events = iterator_to_array($this->reader->readEvents($orgId, $projectId));

        $this->assertCount(1, $events);
        $this->assertSame($orgId, $events[0]['organization_id']);
    }

    public function testCombinedFiltersWork(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $targetDate = new \DateTimeImmutable('2026-01-17');

        // Create the target event
        $targetTimestamp = strtotime('2026-01-17 10:00:00') * 1000;
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'span', $targetTimestamp, 'Target event')]);

        // Create noise events
        $this->writer->writeEvents([$this->createEvent('other-org', $projectId, 'span', $targetTimestamp, 'Wrong org')]);
        $this->writer->writeEvents([$this->createEvent($orgId, 'other-proj', 'span', $targetTimestamp, 'Wrong project')]);
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $targetTimestamp, 'Wrong type')]);

        $oldTimestamp = strtotime('2026-01-10 10:00:00') * 1000;
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'span', $oldTimestamp, 'Wrong date')]);

        // Query with all filters
        $events = iterator_to_array($this->reader->readEvents(
            $orgId,
            $projectId,
            'span',
            $targetDate,
            $targetDate
        ));

        $this->assertCount(1, $events);
        $this->assertSame('Target event', $events[0]['message']);
    }

    public function testEmptyResultForNonExistentOrganization(): void
    {
        $events = iterator_to_array($this->reader->readEvents('nonexistent-org'));

        $this->assertCount(0, $events);
    }

    public function testReadEventsWithDateRangeOnlyReadsRelevantPartitions(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();

        // Create events on 3 different days
        $day1 = new \DateTimeImmutable('2024-01-15 10:00:00');
        $day2 = new \DateTimeImmutable('2024-01-16 10:00:00');
        $day3 = new \DateTimeImmutable('2024-01-17 10:00:00');

        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $day1->getTimestamp() * 1000, 'event-day1')]);
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $day2->getTimestamp() * 1000, 'event-day2')]);
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $day3->getTimestamp() * 1000, 'event-day3')]);

        // Query only days 1 and 2
        $events = iterator_to_array($this->reader->readEvents(
            $orgId,
            $projectId,
            'log',
            $day1,
            $day2,
        ));

        // Should return 2 events, not 3
        $this->assertCount(2, $events);
        $messages = array_column($events, 'message');
        $this->assertContains('event-day1', $messages);
        $this->assertContains('event-day2', $messages);
        $this->assertNotContains('event-day3', $messages);
    }

    public function testReadEventsWithColumnsUsesDatePartitionPruning(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();

        // Create events on 3 different days
        $day1 = new \DateTimeImmutable('2024-01-15 10:00:00');
        $day2 = new \DateTimeImmutable('2024-01-16 10:00:00');
        $day3 = new \DateTimeImmutable('2024-01-17 10:00:00');

        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $day1->getTimestamp() * 1000, 'col-event-day1')]);
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $day2->getTimestamp() * 1000, 'col-event-day2')]);
        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'log', $day3->getTimestamp() * 1000, 'col-event-day3')]);

        // Query only day 2 using readEventsWithColumns
        $events = iterator_to_array($this->reader->readEventsWithColumns(
            organizationId: $orgId,
            projectId: $projectId,
            from: $day2,
            to: $day2,
            columns: ['event_id', 'message', 'timestamp'],
        ));

        // Should return only 1 event
        $this->assertCount(1, $events);
        $this->assertSame('col-event-day2', $events[0]['message']);
    }

    public function testReadEventsWithColumnsUsesOrganizationPartitionPruning(): void
    {
        $org1 = 'org-1-'.Uuid::v7();
        $org2 = 'org-2-'.Uuid::v7();
        $projectId = 'proj-'.Uuid::v7();
        $timestamp = time() * 1000;
        $date = new \DateTimeImmutable('now');

        // Create events in two different organizations
        $this->writer->writeEvents([$this->createEvent($org1, $projectId, 'log', $timestamp, 'Org 1 secret data')]);
        $this->writer->writeEvents([$this->createEvent($org2, $projectId, 'log', $timestamp, 'Org 2 secret data')]);

        // Query only org1 using readEventsWithColumns
        $events = iterator_to_array($this->reader->readEventsWithColumns(
            organizationId: $org1,
            projectId: $projectId,
            from: $date,
            to: $date,
            columns: ['event_id', 'message', 'organization_id'],
        ));

        // Should return only org1's event
        $this->assertCount(1, $events);
        $this->assertSame('Org 1 secret data', $events[0]['message']);
        $this->assertSame($org1, $events[0]['organization_id']);
    }

    public function testReadEventsWithColumnsUsesProjectPartitionPruning(): void
    {
        $orgId = 'org-'.Uuid::v7();
        $proj1 = 'proj-1-'.Uuid::v7();
        $proj2 = 'proj-2-'.Uuid::v7();
        $timestamp = time() * 1000;
        $date = new \DateTimeImmutable('now');

        // Create events in two different projects
        $this->writer->writeEvents([$this->createEvent($orgId, $proj1, 'log', $timestamp, 'Project 1 data')]);
        $this->writer->writeEvents([$this->createEvent($orgId, $proj2, 'log', $timestamp, 'Project 2 data')]);

        // Query only proj1 using readEventsWithColumns
        $events = iterator_to_array($this->reader->readEventsWithColumns(
            organizationId: $orgId,
            projectId: $proj1,
            from: $date,
            to: $date,
            columns: ['event_id', 'message', 'project_id'],
        ));

        // Should return only proj1's event
        $this->assertCount(1, $events);
        $this->assertSame('Project 1 data', $events[0]['message']);
        $this->assertSame($proj1, $events[0]['project_id']);
    }

    public function testReadEventsWithColumnsIsolatesOrganizations(): void
    {
        $org1 = 'org-isolated-1-'.Uuid::v7();
        $org2 = 'org-isolated-2-'.Uuid::v7();
        $proj1 = 'proj-'.Uuid::v7();
        $proj2 = 'proj-'.Uuid::v7();

        // Create events across multiple orgs and projects on multiple days
        $day1 = new \DateTimeImmutable('2024-02-10 10:00:00');
        $day2 = new \DateTimeImmutable('2024-02-11 10:00:00');

        // Org1 events
        $this->writer->writeEvents([$this->createEvent($org1, $proj1, 'log', $day1->getTimestamp() * 1000, 'org1-proj1-day1')]);
        $this->writer->writeEvents([$this->createEvent($org1, $proj1, 'log', $day2->getTimestamp() * 1000, 'org1-proj1-day2')]);

        // Org2 events (should never be returned when querying org1)
        $this->writer->writeEvents([$this->createEvent($org2, $proj2, 'log', $day1->getTimestamp() * 1000, 'org2-proj2-day1')]);
        $this->writer->writeEvents([$this->createEvent($org2, $proj2, 'log', $day2->getTimestamp() * 1000, 'org2-proj2-day2')]);

        // Query org1 across both days
        $events = iterator_to_array($this->reader->readEventsWithColumns(
            organizationId: $org1,
            projectId: $proj1,
            from: $day1,
            to: $day2,
            columns: ['event_id', 'message', 'organization_id', 'project_id'],
        ));

        // Should return exactly 2 events from org1
        $this->assertCount(2, $events);

        $messages = array_column($events, 'message');
        $this->assertContains('org1-proj1-day1', $messages);
        $this->assertContains('org1-proj1-day2', $messages);

        // Verify no org2 data leaked
        $this->assertNotContains('org2-proj2-day1', $messages);
        $this->assertNotContains('org2-proj2-day2', $messages);

        // Verify all returned events are from org1
        foreach ($events as $event) {
            $this->assertSame($org1, $event['organization_id']);
            $this->assertSame($proj1, $event['project_id']);
        }
    }

    public function testReadEventsWithColumnsCombinesAllPartitionFilters(): void
    {
        $targetOrg = 'target-org-'.Uuid::v7();
        $targetProj = 'target-proj-'.Uuid::v7();
        $otherOrg = 'other-org-'.Uuid::v7();
        $otherProj = 'other-proj-'.Uuid::v7();

        $targetDate = new \DateTimeImmutable('2024-03-15 12:00:00');
        $otherDate = new \DateTimeImmutable('2024-03-16 12:00:00');

        // Create the target event
        $this->writer->writeEvents([$this->createEvent(
            $targetOrg,
            $targetProj,
            'log',
            $targetDate->getTimestamp() * 1000,
            'TARGET'
        )]);

        // Create noise events in various partitions
        $this->writer->writeEvents([$this->createEvent($otherOrg, $targetProj, 'log', $targetDate->getTimestamp() * 1000, 'wrong-org')]);
        $this->writer->writeEvents([$this->createEvent($targetOrg, $otherProj, 'log', $targetDate->getTimestamp() * 1000, 'wrong-proj')]);
        $this->writer->writeEvents([$this->createEvent($targetOrg, $targetProj, 'log', $otherDate->getTimestamp() * 1000, 'wrong-date')]);
        $this->writer->writeEvents([$this->createEvent($otherOrg, $otherProj, 'log', $otherDate->getTimestamp() * 1000, 'all-wrong')]);

        // Query with all partition filters
        $events = iterator_to_array($this->reader->readEventsWithColumns(
            organizationId: $targetOrg,
            projectId: $targetProj,
            from: $targetDate,
            to: $targetDate,
            columns: ['event_id', 'message', 'organization_id', 'project_id', 'timestamp'],
        ));

        // Should return exactly 1 event - the target
        $this->assertCount(1, $events);
        $this->assertSame('TARGET', $events[0]['message']);
        $this->assertSame($targetOrg, $events[0]['organization_id']);
        $this->assertSame($targetProj, $events[0]['project_id']);
    }

    public function testPartitionDirectoryStructure(): void
    {
        $orgId = 'org-structure-test';
        $projectId = 'proj-structure-test';
        $timestamp = strtotime('2026-01-17 12:00:00') * 1000;

        $this->writer->writeEvents([$this->createEvent($orgId, $projectId, 'span', $timestamp)]);

        // Verify the directory structure exists
        $expectedDirs = [
            $this->storagePath.'/organization_id='.$orgId,
            $this->storagePath.'/organization_id='.$orgId.'/project_id='.$projectId,
            $this->storagePath.'/organization_id='.$orgId.'/project_id='.$projectId.'/event_type=span',
            $this->storagePath.'/organization_id='.$orgId.'/project_id='.$projectId.'/event_type=span/dt=2026-01-17',
        ];

        foreach ($expectedDirs as $dir) {
            $this->assertDirectoryExists($dir, "Expected directory not found: $dir");
        }
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
