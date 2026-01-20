<?php

declare(strict_types=1);

namespace App\Service\Storage;

use Flow\Filesystem\FilesystemTable;
use Flow\Filesystem\Local\MemoryFilesystem;
use Flow\Filesystem\Local\NativeLocalFilesystem;

use function Flow\Filesystem\Bridge\AsyncAWS\DSL\aws_s3_client;
use function Flow\Filesystem\Bridge\AsyncAWS\DSL\aws_s3_filesystem;
use function Flow\Filesystem\DSL\fstab;

/**
 * Factory for creating the appropriate filesystem based on configuration.
 *
 * Supports local filesystem and S3-compatible storage (AWS S3, MinIO, etc.).
 */
final class StorageFactory
{
    private ?FilesystemTable $filesystemTable = null;

    public function __construct(
        private readonly string $storageType,
        private readonly string $localPath,
        private readonly ?string $s3Bucket = null,
        private readonly ?string $s3Region = null,
        private readonly ?string $s3Endpoint = null,
        private readonly ?string $s3AccessKey = null,
        private readonly ?string $s3SecretKey = null,
        private readonly bool $s3UsePathStyle = false,
    ) {
    }

    /**
     * Create and return the FilesystemTable instance.
     *
     * The FilesystemTable is lazily created and cached for reuse.
     */
    public function createFilesystemTable(): FilesystemTable
    {
        if (null !== $this->filesystemTable) {
            return $this->filesystemTable;
        }

        $this->filesystemTable = match ($this->storageType) {
            's3' => $this->createS3FilesystemTable(),
            'memory' => fstab(new MemoryFilesystem()),
            default => fstab(new NativeLocalFilesystem()),
        };

        return $this->filesystemTable;
    }

    /**
     * Get the base path for storage.
     *
     * For S3: returns 'aws-s3://' protocol prefix
     * For local: returns the configured local storage path
     */
    public function getBasePath(): string
    {
        return match ($this->storageType) {
            's3' => 'aws-s3://',
            'memory' => 'memory://',
            default => $this->localPath,
        };
    }

    /**
     * Check if S3 storage is configured.
     */
    public function isS3Storage(): bool
    {
        return 's3' === $this->storageType;
    }

    /**
     * Check if memory storage is configured (for testing).
     */
    public function isMemoryStorage(): bool
    {
        return 'memory' === $this->storageType;
    }

    /**
     * Check if stream-based operations are needed.
     *
     * S3 and memory filesystems require stream-based I/O,
     * while local filesystem can use direct file paths.
     */
    public function requiresStreamOperations(): bool
    {
        return 's3' === $this->storageType || 'memory' === $this->storageType;
    }

    /**
     * Get the storage type.
     */
    public function getStorageType(): string
    {
        return $this->storageType;
    }

    /**
     * Create an S3-backed FilesystemTable using the AsyncAWS bridge.
     */
    private function createS3FilesystemTable(): FilesystemTable
    {
        if (null === $this->s3Bucket || '' === $this->s3Bucket) {
            throw new \InvalidArgumentException('S3 bucket must be configured when using S3 storage type');
        }

        /** @var array<string, mixed> $clientConfig */
        $clientConfig = [
            'region' => $this->s3Region ?? 'us-east-1',
            'accessKeyId' => $this->s3AccessKey,
            'accessKeySecret' => $this->s3SecretKey,
        ];

        if (null !== $this->s3Endpoint && '' !== $this->s3Endpoint) {
            $clientConfig['endpoint'] = $this->s3Endpoint;
        }

        if ($this->s3UsePathStyle) {
            $clientConfig['pathStyleEndpoint'] = true;
        }

        $s3Filesystem = aws_s3_filesystem(
            $this->s3Bucket,
            aws_s3_client($clientConfig)
        );

        return fstab($s3Filesystem);
    }
}
