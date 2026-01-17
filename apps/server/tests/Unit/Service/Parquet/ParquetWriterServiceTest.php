<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Parquet;

use App\Service\Parquet\ParquetWriterService;
use App\Service\Telemetry\TracerFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ParquetWriterServiceTest extends TestCase
{
    private string $tempDir;
    private ParquetWriterService $writer;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/parquet_test_'.uniqid();
        mkdir($this->tempDir, 0777, true);

        $tracerFactory = new TracerFactory(
            enabled: false,
            serviceName: 'test',
            serviceVersion: '1.0.0',
            exporterEndpoint: 'console',
            samplerType: 'always_off',
            samplerArg: 0.0,
            environment: 'test',
        );

        $this->writer = new ParquetWriterService(
            $this->tempDir,
            new NullLogger(),
            $tracerFactory,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testGetFilePathGeneratesHiveStylePath(): void
    {
        $organizationId = 'org-123';
        $projectId = 'proj-456';
        $eventType = 'span';
        $timestamp = strtotime('2026-01-17 14:30:00') * 1000;

        $path = $this->writer->getFilePath($organizationId, $projectId, $eventType, $timestamp);

        $this->assertStringContainsString('organization_id=org-123', $path);
        $this->assertStringContainsString('project_id=proj-456', $path);
        $this->assertStringContainsString('event_type=span', $path);
        $this->assertStringContainsString('dt=2026-01-17', $path);
        $this->assertStringEndsWith('.parquet', $path);
    }

    public function testGetFilePathWithAllEventTypes(): void
    {
        $timestamp = time() * 1000;
        $eventTypes = ['span', 'log', 'metric', 'error', 'crash'];

        foreach ($eventTypes as $eventType) {
            $path = $this->writer->getFilePath('org', 'proj', $eventType, $timestamp);
            $this->assertStringContainsString("event_type=$eventType", $path);
        }
    }

    public function testPathContainsCorrectDatePartition(): void
    {
        $dates = [
            '2026-01-17 00:00:00' => 'dt=2026-01-17',
            '2025-12-31 23:59:59' => 'dt=2025-12-31',
            '2024-06-15 12:30:00' => 'dt=2024-06-15',
        ];

        foreach ($dates as $dateStr => $expectedPartition) {
            $timestamp = strtotime($dateStr) * 1000;
            $path = $this->writer->getFilePath('org', 'proj', 'span', $timestamp);
            $this->assertStringContainsString($expectedPartition, $path, "Failed for date: $dateStr");
        }
    }

    public function testFileNameContainsTimeAndUuid(): void
    {
        $timestamp = strtotime('2026-01-17 15:58:14') * 1000;
        $path = $this->writer->getFilePath('org', 'proj', 'span', $timestamp);

        $filename = basename($path);

        // Should start with events_
        $this->assertStringStartsWith('events_', $filename);
        // Should contain time like 155814
        $this->assertMatchesRegularExpression('/events_\d{6}_/', $filename);
        // Should end with UUID.parquet
        $this->assertMatchesRegularExpression('/[0-9a-f-]{36}\.parquet$/', $filename);
    }

    public function testGetProjectStoragePathGeneratesHiveStylePath(): void
    {
        $path = $this->writer->getProjectStoragePath('org-123', 'proj-456');

        $this->assertStringContainsString('organization_id=org-123', $path);
        $this->assertStringContainsString('project_id=proj-456', $path);
    }

    public function testWriteEventsCreatesFileInHiveStructure(): void
    {
        $events = [
            [
                'event_id' => 'evt-1',
                'timestamp' => strtotime('2026-01-17 10:00:00') * 1000,
                'organization_id' => 'org-test',
                'project_id' => 'proj-test',
                'event_type' => 'log',
                'severity' => 'info',
                'message' => 'Test log message',
            ],
        ];

        $filePath = $this->writer->writeEvents($events);

        $this->assertFileExists($filePath);
        $this->assertStringContainsString('organization_id=org-test', $filePath);
        $this->assertStringContainsString('project_id=proj-test', $filePath);
        $this->assertStringContainsString('event_type=log', $filePath);
        $this->assertStringContainsString('dt=2026-01-17', $filePath);
    }

    public function testWriteEventsThrowsExceptionForEmptyArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No events to write');

        $this->writer->writeEvents([]);
    }

    public function testWriteEventsHandlesMissingPartitionKeys(): void
    {
        $events = [
            [
                'event_id' => 'evt-1',
                'message' => 'Test message',
                // Missing organization_id, project_id, event_type
            ],
        ];

        $filePath = $this->writer->writeEvents($events);

        $this->assertFileExists($filePath);
        $this->assertStringContainsString('organization_id=unknown', $filePath);
        $this->assertStringContainsString('project_id=unknown', $filePath);
        $this->assertStringContainsString('event_type=unknown', $filePath);
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
