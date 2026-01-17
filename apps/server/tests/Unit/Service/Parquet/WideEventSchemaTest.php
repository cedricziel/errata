<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Parquet;

use App\Service\Parquet\WideEventSchema;
use PHPUnit\Framework\TestCase;

class WideEventSchemaTest extends TestCase
{
    public function testSchemaContainsOrganizationIdColumn(): void
    {
        $schema = WideEventSchema::getSchema();
        $columns = $schema->columns();

        $columnNames = array_map(fn ($col) => $col->name(), $columns);

        $this->assertContains('organization_id', $columnNames);
    }

    public function testSchemaContainsProjectIdColumn(): void
    {
        $schema = WideEventSchema::getSchema();
        $columns = $schema->columns();

        $columnNames = array_map(fn ($col) => $col->name(), $columns);

        $this->assertContains('project_id', $columnNames);
    }

    public function testSchemaContainsEventTypeColumn(): void
    {
        $schema = WideEventSchema::getSchema();
        $columns = $schema->columns();

        $columnNames = array_map(fn ($col) => $col->name(), $columns);

        $this->assertContains('event_type', $columnNames);
    }

    public function testOrganizationIdComesAfterProjectId(): void
    {
        $schema = WideEventSchema::getSchema();
        $columns = $schema->columns();

        $columnNames = array_map(fn ($col) => $col->name(), $columns);
        $orgIndex = array_search('organization_id', $columnNames, true);
        $projectIndex = array_search('project_id', $columnNames, true);

        $this->assertNotFalse($orgIndex);
        $this->assertNotFalse($projectIndex);
        $this->assertLessThan($projectIndex, $orgIndex, 'organization_id should come before project_id');
    }

    public function testNormalizeIncludesOrganizationId(): void
    {
        $data = ['event_id' => 'test-id'];
        $normalized = WideEventSchema::normalize($data);

        $this->assertArrayHasKey('organization_id', $normalized);
    }

    public function testNormalizePreservesOrganizationIdWhenProvided(): void
    {
        $data = [
            'event_id' => 'test-id',
            'organization_id' => 'org-123',
        ];
        $normalized = WideEventSchema::normalize($data);

        $this->assertSame('org-123', $normalized['organization_id']);
    }

    public function testNormalizeDefaultsOrganizationIdToNull(): void
    {
        $data = ['event_id' => 'test-id'];
        $normalized = WideEventSchema::normalize($data);

        $this->assertNull($normalized['organization_id']);
    }

    public function testNormalizeIncludesAllPartitionKeys(): void
    {
        $data = [];
        $normalized = WideEventSchema::normalize($data);

        // All partition keys should be present
        $this->assertArrayHasKey('organization_id', $normalized);
        $this->assertArrayHasKey('project_id', $normalized);
        $this->assertArrayHasKey('event_type', $normalized);
        $this->assertArrayHasKey('timestamp', $normalized);
    }

    public function testEventTypeConstantsExist(): void
    {
        $this->assertSame('span', WideEventSchema::EVENT_TYPE_SPAN);
        $this->assertSame('log', WideEventSchema::EVENT_TYPE_LOG);
        $this->assertSame('metric', WideEventSchema::EVENT_TYPE_METRIC);
        $this->assertSame('error', WideEventSchema::EVENT_TYPE_ERROR);
        $this->assertSame('crash', WideEventSchema::EVENT_TYPE_CRASH);
    }
}
