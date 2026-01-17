<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Service\Parquet\ParquetWriterService;
use App\Service\Parquet\WideEventSchema;
use App\Tests\Integration\AbstractIntegrationTestCase;
use Symfony\Component\Uid\Uuid;

class OtelControllerTest extends AbstractIntegrationTestCase
{
    private ParquetWriterService $parquetWriter;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ParquetWriterService $parquetWriter */
        $parquetWriter = static::getContainer()->get(ParquetWriterService::class);
        $this->parquetWriter = $parquetWriter;
    }

    protected function tearDown(): void
    {
        // Clean up parquet test files
        $this->cleanupParquetFiles();
        parent::tearDown();
    }

    private function cleanupParquetFiles(): void
    {
        $storagePath = static::getContainer()->getParameter('kernel.project_dir').'/storage/parquet';
        if (is_dir($storagePath)) {
            $this->recursiveDelete($storagePath);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ==================== Authentication Tests ====================

    public function testTracesIndexRequiresAuthentication(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);

        $this->browser()
            ->interceptRedirects()
            ->visit('/projects/'.$project->getPublicId()->toRfc4122().'/otel/traces')
            ->assertRedirectedTo('/login');
    }

    public function testLogsIndexRequiresAuthentication(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);

        $this->browser()
            ->interceptRedirects()
            ->visit('/projects/'.$project->getPublicId()->toRfc4122().'/otel/logs')
            ->assertRedirectedTo('/login');
    }

    public function testMetricsIndexRequiresAuthentication(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);

        $this->browser()
            ->interceptRedirects()
            ->visit('/projects/'.$project->getPublicId()->toRfc4122().'/otel/metrics')
            ->assertRedirectedTo('/login');
    }

    // ==================== Traces Tests ====================

    public function testTracesIndexShowsEmptyState(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$project->getPublicId()->toRfc4122().'/otel/traces')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Traces')
            ->assertSeeIn('body', 'No traces found');
    }

    public function testTracesIndexShowsTraces(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $traceId = Uuid::v4()->toRfc4122();
        $this->createTestSpan($projectId, $traceId, 'root-span', 'GET /api/users', null);
        $this->createTestSpan($projectId, $traceId, 'child-span', 'database.query', 'root-span');

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$projectId.'/otel/traces')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Traces')
            ->assertSeeIn('body', 'GET /api/users');
    }

    public function testTracesShowDisplaysSpanWaterfall(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $traceId = Uuid::v4()->toRfc4122();
        $this->createTestSpan($projectId, $traceId, 'root-span', 'GET /api/users', null, 100.0);
        $this->createTestSpan($projectId, $traceId, 'child-span', 'database.query', 'root-span', 50.0);

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$projectId.'/otel/traces/'.$traceId)
            ->assertSuccessful()
            ->assertSeeIn('body', 'Trace Details')
            ->assertSeeIn('body', 'GET /api/users')
            ->assertSeeIn('body', 'database.query')
            ->assertSeeIn('body', 'Span Waterfall');
    }

    public function testTracesShowReturns404ForNonExistentTrace(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$projectId.'/otel/traces/'.Uuid::v4()->toRfc4122())
            ->assertStatus(404);
    }

    public function testTracesFilterByDateRange(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $traceId = Uuid::v4()->toRfc4122();
        $this->createTestSpan($projectId, $traceId, 'root-span', 'GET /api/users', null);

        $from = date('Y-m-d\TH:i', strtotime('-1 hour'));
        $to = date('Y-m-d\TH:i', strtotime('+1 hour'));

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$projectId.'/otel/traces?from='.$from.'&to='.$to)
            ->assertSuccessful()
            ->assertSeeIn('body', 'GET /api/users');
    }

    // ==================== Logs Tests ====================

    public function testLogsIndexShowsEmptyState(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$project->getPublicId()->toRfc4122().'/otel/logs')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Logs')
            ->assertSeeIn('body', 'No logs found');
    }

    public function testLogsIndexShowsLogs(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $this->createTestLog($projectId, 'User logged in successfully', 'info');
        $this->createTestLog($projectId, 'Failed to connect to database', 'error');

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$projectId.'/otel/logs')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Logs')
            ->assertSeeIn('body', 'User logged in successfully')
            ->assertSeeIn('body', 'Failed to connect to database');
    }

    public function testLogsFilterBySeverity(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $this->createTestLog($projectId, 'Info message', 'info');
        $this->createTestLog($projectId, 'Error message', 'error');

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$projectId.'/otel/logs?severity=error')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Error message')
            ->assertNotSeeIn('body', 'Info message');
    }

    public function testLogsFilterBySearch(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $this->createTestLog($projectId, 'User authentication successful', 'info');
        $this->createTestLog($projectId, 'Database connection failed', 'error');

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$projectId.'/otel/logs?search=authentication')
            ->assertSuccessful()
            ->assertSeeIn('body', 'User authentication successful')
            ->assertNotSeeIn('body', 'Database connection failed');
    }

    public function testLogsShowDisplaysLogDetail(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $eventId = Uuid::v4()->toRfc4122();
        $this->createTestLog($projectId, 'Detailed log message for testing', 'warning', $eventId);

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$projectId.'/otel/logs/'.$eventId)
            ->assertSuccessful()
            ->assertSeeIn('body', 'Log Detail')
            ->assertSeeIn('body', 'Detailed log message for testing')
            ->assertSeeIn('body', 'WARNING');
    }

    public function testLogsShowReturns404ForNonExistentLog(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$projectId.'/otel/logs/'.Uuid::v4()->toRfc4122())
            ->assertStatus(404);
    }

    public function testLogsShowDisplaysTraceCorrelation(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $eventId = Uuid::v4()->toRfc4122();
        $traceId = Uuid::v4()->toRfc4122();
        $this->createTestLog($projectId, 'Log with trace correlation', 'info', $eventId, $traceId);

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$projectId.'/otel/logs/'.$eventId)
            ->assertSuccessful()
            ->assertSeeIn('body', 'Trace Correlation')
            ->assertSeeIn('body', $traceId);
    }

    // ==================== Metrics Tests ====================

    public function testMetricsIndexShowsEmptyState(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$project->getPublicId()->toRfc4122().'/otel/metrics')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Metrics')
            ->assertSeeIn('body', 'No metrics found');
    }

    public function testMetricsIndexShowsMetrics(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $this->createTestMetric($projectId, 'http.request.duration', 150.5, 'ms');
        $this->createTestMetric($projectId, 'memory.usage', 1024000.0, 'bytes');

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$projectId.'/otel/metrics')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Metrics')
            ->assertSeeIn('body', 'http.request.duration')
            ->assertSeeIn('body', 'memory.usage');
    }

    public function testMetricsFilterByMetricName(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $this->createTestMetric($projectId, 'http.request.duration', 150.5, 'ms');
        $this->createTestMetric($projectId, 'memory.usage', 1024000.0, 'bytes');

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$projectId.'/otel/metrics?metric=http.request.duration')
            ->assertSuccessful()
            ->assertSeeIn('table', 'http.request.duration')
            ->assertNotSeeIn('table', 'memory.usage');
    }

    // ==================== Access Control Tests ====================

    public function testCannotAccessOtherOrganizationsTraces(): void
    {
        $user1 = $this->createTestUser('user1@example.com');
        $user2 = $this->createTestUser('user2@example.com');

        $project1 = $this->createTestProject($user1);
        $projectId = $project1->getPublicId()->toRfc4122();

        $traceId = Uuid::v4()->toRfc4122();
        $this->createTestSpan($projectId, $traceId, 'root-span', 'GET /api/users', null);

        // user2 should not have access to user1's project
        $this->browser()
            ->actingAs($user2)
            ->visit('/projects/'.$projectId.'/otel/traces')
            ->assertStatus(404);
    }

    public function testCannotAccessOtherOrganizationsLogs(): void
    {
        $user1 = $this->createTestUser('user1@example.com');
        $user2 = $this->createTestUser('user2@example.com');

        $project1 = $this->createTestProject($user1);
        $projectId = $project1->getPublicId()->toRfc4122();

        $this->createTestLog($projectId, 'Secret log message', 'info');

        // user2 should not have access to user1's project
        $this->browser()
            ->actingAs($user2)
            ->visit('/projects/'.$projectId.'/otel/logs')
            ->assertStatus(404);
    }

    public function testCannotAccessOtherOrganizationsMetrics(): void
    {
        $user1 = $this->createTestUser('user1@example.com');
        $user2 = $this->createTestUser('user2@example.com');

        $project1 = $this->createTestProject($user1);
        $projectId = $project1->getPublicId()->toRfc4122();

        $this->createTestMetric($projectId, 'secret.metric', 42.0, 'count');

        // user2 should not have access to user1's project
        $this->browser()
            ->actingAs($user2)
            ->visit('/projects/'.$projectId.'/otel/metrics')
            ->assertStatus(404);
    }

    public function testNonExistentProjectReturns404(): void
    {
        $user = $this->createTestUser();
        $fakeProjectId = Uuid::v4()->toRfc4122();

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$fakeProjectId.'/otel/traces')
            ->assertStatus(404);
    }

    // ==================== Helper Methods ====================

    private function createTestSpan(
        string $projectId,
        string $traceId,
        string $spanId,
        string $operation,
        ?string $parentSpanId = null,
        float $durationMs = 100.0,
        string $status = 'ok',
    ): void {
        $event = [
            'event_id' => Uuid::v4()->toRfc4122(),
            'timestamp' => (int) (microtime(true) * 1000),
            'project_id' => $projectId,
            'event_type' => WideEventSchema::EVENT_TYPE_SPAN,
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'operation' => $operation,
            'duration_ms' => $durationMs,
            'span_status' => $status,
        ];

        $this->parquetWriter->writeEvent($event);
    }

    private function createTestLog(
        string $projectId,
        string $message,
        string $severity = 'info',
        ?string $eventId = null,
        ?string $traceId = null,
    ): void {
        $event = [
            'event_id' => $eventId ?? Uuid::v4()->toRfc4122(),
            'timestamp' => (int) (microtime(true) * 1000),
            'project_id' => $projectId,
            'event_type' => WideEventSchema::EVENT_TYPE_LOG,
            'message' => $message,
            'severity' => $severity,
            'trace_id' => $traceId,
            'environment' => 'test',
        ];

        $this->parquetWriter->writeEvent($event);
    }

    private function createTestMetric(
        string $projectId,
        string $metricName,
        float $value,
        string $unit = '',
    ): void {
        $event = [
            'event_id' => Uuid::v4()->toRfc4122(),
            'timestamp' => (int) (microtime(true) * 1000),
            'project_id' => $projectId,
            'event_type' => WideEventSchema::EVENT_TYPE_METRIC,
            'metric_name' => $metricName,
            'metric_value' => $value,
            'metric_unit' => $unit,
            'environment' => 'test',
        ];

        $this->parquetWriter->writeEvent($event);
    }
}
