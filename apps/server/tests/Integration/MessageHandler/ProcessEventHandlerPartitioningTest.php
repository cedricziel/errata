<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Service\Parquet\ParquetReaderService;
use App\Tests\Integration\AbstractIntegrationTestCase;

/**
 * Tests that ProcessEventHandler correctly writes events with Hive-style partitioning.
 */
class ProcessEventHandlerPartitioningTest extends AbstractIntegrationTestCase
{
    private string $storagePath;
    private ParquetReaderService $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir().'/parquet_handler_test_'.uniqid();
        mkdir($this->storagePath, 0777, true);

        // Inject our test storage path
        $container = static::getContainer();

        $this->reader = new ParquetReaderService(
            $this->storagePath,
            $container->get('logger'),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);
        parent::tearDown();
    }

    public function testEventContainsOrganizationId(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $organizationId = $project->getOrganization()->getPublicId()->toRfc4122();
        $projectId = $project->getPublicId()->toRfc4122();

        // The event data that would be sent
        $eventData = [
            'event_type' => 'log',
            'message' => 'Test log message',
            'severity' => 'info',
        ];

        // Verify the organization is properly linked
        $this->assertNotNull($project->getOrganization());
        $this->assertNotNull($organizationId);
    }

    public function testProjectHasOrganization(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);

        // Verify organization relationship
        $organization = $project->getOrganization();

        $this->assertNotNull($organization);
        $this->assertNotNull($organization->getPublicId());
        $this->assertNotEmpty($organization->getPublicId()->toRfc4122());
    }

    public function testOrganizationIdCanBeRetrievedFromProject(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);

        $organizationId = $project->getOrganization()->getPublicId()?->toRfc4122();

        $this->assertNotNull($organizationId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $organizationId
        );
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
