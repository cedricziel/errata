<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Parquet;

use App\Service\Parquet\FlowConfigFactory;
use App\Service\Parquet\ParquetReaderService;
use App\Service\Storage\StorageFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for ParquetReaderService.
 *
 * Note: Most functionality is now delegated to Flow-PHP DataFrame API.
 * These tests focus on filter conversion and service initialization.
 */
class ParquetReaderServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/parquet_reader_test_'.uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testServiceCanBeInstantiated(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: $this->tempDir,
        );
        $flowConfigFactory = new FlowConfigFactory($storageFactory);

        $reader = new ParquetReaderService(
            $storageFactory,
            $flowConfigFactory,
            new NullLogger(),
        );

        $this->assertInstanceOf(ParquetReaderService::class, $reader);
    }

    public function testReadEventsReturnsEmptyGeneratorForNonexistentPath(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: $this->tempDir,
        );
        $flowConfigFactory = new FlowConfigFactory($storageFactory);

        $reader = new ParquetReaderService(
            $storageFactory,
            $flowConfigFactory,
            new NullLogger(),
        );

        // Reading from a non-existent organization should return empty
        $events = iterator_to_array($reader->readEvents('nonexistent-org', 'nonexistent-proj'));

        $this->assertSame([], $events);
    }

    public function testCountEventsReturnsZeroForEmptyStorage(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: $this->tempDir,
        );
        $flowConfigFactory = new FlowConfigFactory($storageFactory);

        $reader = new ParquetReaderService(
            $storageFactory,
            $flowConfigFactory,
            new NullLogger(),
        );

        $count = $reader->countEvents('nonexistent-org', 'nonexistent-proj');

        $this->assertSame(0, $count);
    }

    public function testGetEventsByFingerprintReturnsEmptyForNonexistentData(): void
    {
        $storageFactory = new StorageFactory(
            storageType: 'local',
            localPath: $this->tempDir,
        );
        $flowConfigFactory = new FlowConfigFactory($storageFactory);

        $reader = new ParquetReaderService(
            $storageFactory,
            $flowConfigFactory,
            new NullLogger(),
        );

        $events = $reader->getEventsByFingerprint(
            'nonexistent-fingerprint',
            'nonexistent-org',
            'nonexistent-proj'
        );

        $this->assertSame([], $events);
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
