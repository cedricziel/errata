<?php

declare(strict_types=1);

namespace App\Tests\Integration\EndToEnd;

use App\Message\ExecuteQuery;
use App\Message\ProcessEvent;
use App\MessageHandler\ExecuteQueryHandler;
use App\Service\QueryBuilder\AsyncQueryResultStore;
use App\Service\Storage\StorageFactory;
use App\Tests\Integration\AbstractIntegrationTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Browser\HttpOptions;
use Zenstruck\Messenger\Test\InteractsWithMessenger;

/**
 * End-to-end test for the complete metric ingestion → parquet write → query flow.
 *
 * This test proves:
 * 1. OTLP metric ingestion works via /v1/metrics
 * 2. ProcessEvent handler writes to parquet with all attributes
 * 3. Query execution can read the data back
 * 4. Timeframe filtering works correctly
 * 5. Different metric types (gauge, counter, histogram) are supported
 */
class MetricIngestionQueryTest extends AbstractIntegrationTestCase
{
    use InteractsWithMessenger;

    public function testFullMetricIngestionAndQueryFlow(): void
    {
        // 1. Setup: Create project and API key
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();
        $projectId = $project->getPublicId()->toRfc4122();

        // 2. Ingest: Send OTLP metric with a recent timestamp
        $now = (int) (microtime(true) * 1_000_000_000); // nanoseconds
        $metricName = 'http.request.duration';
        $metricValue = 150.5;
        $metricUnit = 'ms';
        $metricPayload = $this->createOtlpGaugeMetricPayload('test-metric-service', $metricName, $metricValue, $metricUnit, $now);

        $this->browser()
            ->post('/v1/metrics', HttpOptions::json($metricPayload)
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);

        // Verify ProcessEvent was dispatched
        $this->transport('async_events')
            ->queue()
            ->assertContains(ProcessEvent::class, 1);

        // Verify the message contains correct data - ProcessEvent has ALL attributes
        $messages = $this->transport('async_events')->queue()->messages(ProcessEvent::class);
        $this->assertCount(1, $messages, 'Should have exactly one ProcessEvent message');

        // Assert ProcessEvent attributes comprehensively
        $eventData = $messages[0]->eventData;
        $this->assertSame('metric', $eventData['event_type'], 'ProcessEvent should have event_type=metric');
        $this->assertSame($metricName, $eventData['metric_name'], 'ProcessEvent should have correct metric_name');
        $this->assertSame($metricValue, $eventData['metric_value'], 'ProcessEvent should have correct metric_value');
        $this->assertSame($metricUnit, $eventData['metric_unit'], 'ProcessEvent should have correct metric_unit');
        $this->assertSame('test-metric-service', $eventData['bundle_id'], 'ProcessEvent should have correct bundle_id (service.name)');

        // 3. Process: Handle the ProcessEvent message (writes to parquet)
        $this->transport('async_events')->process(1);

        // Verify parquet files exist after processing
        /** @var StorageFactory $storageFactory */
        $storageFactory = static::getContainer()->get(StorageFactory::class);
        $basePath = $storageFactory->getLocalPath();

        $parquetFiles = glob($basePath.'/organization_id='.$organizationId.'/project_id='.$projectId.'/event_type=*/dt=*/*.parquet');
        $this->assertNotEmpty($parquetFiles, 'Parquet files should exist after processing');

        // 4. Query: Execute async query for the ingested metric
        /** @var AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(AsyncQueryResultStore::class);
        $queryId = Uuid::v7()->toRfc4122();

        $queryTime = new \DateTimeImmutable();
        $queryRequest = [
            'filters' => [
                ['attribute' => 'event_type', 'operator' => 'eq', 'value' => 'metric'],
            ],
            'groupBy' => null,
            'page' => 1,
            'limit' => 50,
            'projectId' => $projectId,
            'startDate' => $queryTime->modify('-1 hour')->format('c'),
            'endDate' => $queryTime->modify('+1 hour')->format('c'),
        ];

        $resultStore->initializeQuery($queryId, $queryRequest, (string) $user->getId(), $organizationId);

        /** @var ExecuteQueryHandler $handler */
        $handler = static::getContainer()->get(ExecuteQueryHandler::class);
        $handler->__invoke(new ExecuteQuery($queryId, $queryRequest, (string) $user->getId(), $organizationId));

        // 5. Verify: Check query results
        $state = $resultStore->getQueryState($queryId);

        $this->assertNotNull($state, 'Query state should exist');
        $this->assertSame('completed', $state['status'], 'Query should complete successfully');
        $this->assertArrayHasKey('result', $state, 'Query should have result');
        $this->assertGreaterThan(0, $state['result']['total'], 'Should find at least 1 metric');

        // Verify the metric data (note: async query returns subset of columns based on filters)
        $events = $state['result']['events'];
        $this->assertNotEmpty($events, 'Should have at least one event');
        $this->assertSame('metric', $events[0]['event_type'], 'Event type should be metric');
        $this->assertArrayHasKey('timestamp', $events[0], 'Should have timestamp');
        $this->assertArrayHasKey('event_id', $events[0], 'Should have event_id');
    }

    public function testMetricQueryWithEmptyTimeframeReturnsNoResults(): void
    {
        // 1. Setup: Create project and API key
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();

        // 2. Ingest: Send OTLP metric with a timestamp from the past
        $pastTime = (int) ((time() - 86400 * 30) * 1_000_000_000); // 30 days ago in nanoseconds
        $metricPayload = $this->createOtlpGaugeMetricPayload('old-service', 'old.metric', 42.0, '1', $pastTime);

        $this->browser()
            ->post('/v1/metrics', HttpOptions::json($metricPayload)
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);

        // 3. Process: Handle the ProcessEvent message
        $this->transport('async_events')->process(1);

        // 4. Query: Execute query with a timeframe that doesn't include our metric
        /** @var AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(AsyncQueryResultStore::class);
        $queryId = Uuid::v7()->toRfc4122();

        $now = new \DateTimeImmutable();
        $queryRequest = [
            'filters' => [
                ['attribute' => 'event_type', 'operator' => 'eq', 'value' => 'metric'],
            ],
            'groupBy' => null,
            'page' => 1,
            'limit' => 50,
            'projectId' => $project->getPublicId()->toRfc4122(),
            // Query for last hour only - should not find the 30-day-old metric
            'startDate' => $now->modify('-1 hour')->format('c'),
            'endDate' => $now->format('c'),
        ];

        $resultStore->initializeQuery($queryId, $queryRequest, (string) $user->getId(), $organizationId);

        /** @var ExecuteQueryHandler $handler */
        $handler = static::getContainer()->get(ExecuteQueryHandler::class);
        $handler->__invoke(new ExecuteQuery($queryId, $queryRequest, (string) $user->getId(), $organizationId));

        // 5. Verify: Query should complete but find nothing
        $state = $resultStore->getQueryState($queryId);

        $this->assertNotNull($state, 'Query state should exist');
        $this->assertSame('completed', $state['status'], 'Query should complete successfully');
        $this->assertSame(0, $state['result']['total'], 'Should find no metrics in recent timeframe');
    }

    public function testCounterMetricIngestion(): void
    {
        // 1. Setup: Create project and API key
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();
        $projectId = $project->getPublicId()->toRfc4122();

        // 2. Ingest: Send OTLP counter metric
        $now = (int) (microtime(true) * 1_000_000_000);
        $metricName = 'http.request.count';
        $metricValue = 100;
        $metricPayload = $this->createOtlpCounterMetricPayload('counter-service', $metricName, $metricValue, $now);

        $this->browser()
            ->post('/v1/metrics', HttpOptions::json($metricPayload)
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);

        // Verify the message contains correct metric data - ProcessEvent has all fields
        $messages = $this->transport('async_events')->queue()->messages(ProcessEvent::class);
        $this->assertCount(1, $messages);

        $eventData = $messages[0]->eventData;
        $this->assertSame('metric', $eventData['event_type']);
        $this->assertSame($metricName, $eventData['metric_name']);
        $this->assertSame((float) $metricValue, $eventData['metric_value']);
        $this->assertSame('counter-service', $eventData['bundle_id']);
        $this->assertSame('1', $eventData['metric_unit']);

        // 3. Process: Handle the ProcessEvent message
        $this->transport('async_events')->process(1);

        // 4. Query and verify
        /** @var AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(AsyncQueryResultStore::class);
        $queryId = Uuid::v7()->toRfc4122();

        $queryTime = new \DateTimeImmutable();
        $queryRequest = [
            'filters' => [
                ['attribute' => 'event_type', 'operator' => 'eq', 'value' => 'metric'],
            ],
            'groupBy' => null,
            'page' => 1,
            'limit' => 50,
            'projectId' => $projectId,
            'startDate' => $queryTime->modify('-1 hour')->format('c'),
            'endDate' => $queryTime->modify('+1 hour')->format('c'),
        ];

        $resultStore->initializeQuery($queryId, $queryRequest, (string) $user->getId(), $organizationId);

        /** @var ExecuteQueryHandler $handler */
        $handler = static::getContainer()->get(ExecuteQueryHandler::class);
        $handler->__invoke(new ExecuteQuery($queryId, $queryRequest, (string) $user->getId(), $organizationId));

        $state = $resultStore->getQueryState($queryId);
        $this->assertSame('completed', $state['status']);
        $this->assertGreaterThan(0, $state['result']['total'], 'Should find at least 1 counter metric');
    }

    public function testHistogramMetricIngestion(): void
    {
        // 1. Setup: Create project and API key
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();
        $projectId = $project->getPublicId()->toRfc4122();

        // 2. Ingest: Send OTLP histogram metric
        $now = (int) (microtime(true) * 1_000_000_000);
        $metricName = 'http.request.latency';
        $metricPayload = $this->createOtlpHistogramMetricPayload('histogram-service', $metricName, $now);

        $this->browser()
            ->post('/v1/metrics', HttpOptions::json($metricPayload)
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);

        // Verify the message was dispatched - ProcessEvent has all fields
        $messages = $this->transport('async_events')->queue()->messages(ProcessEvent::class);
        $this->assertCount(1, $messages);

        $eventData = $messages[0]->eventData;
        $this->assertSame('metric', $eventData['event_type']);
        $this->assertSame($metricName, $eventData['metric_name']);
        $this->assertSame('histogram-service', $eventData['bundle_id']);
        $this->assertSame('ms', $eventData['metric_unit']);

        // 3. Process: Handle the ProcessEvent message
        $this->transport('async_events')->process(1);

        // 4. Query and verify
        /** @var AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(AsyncQueryResultStore::class);
        $queryId = Uuid::v7()->toRfc4122();

        $queryTime = new \DateTimeImmutable();
        $queryRequest = [
            'filters' => [
                ['attribute' => 'event_type', 'operator' => 'eq', 'value' => 'metric'],
            ],
            'groupBy' => null,
            'page' => 1,
            'limit' => 50,
            'projectId' => $projectId,
            'startDate' => $queryTime->modify('-1 hour')->format('c'),
            'endDate' => $queryTime->modify('+1 hour')->format('c'),
        ];

        $resultStore->initializeQuery($queryId, $queryRequest, (string) $user->getId(), $organizationId);

        /** @var ExecuteQueryHandler $handler */
        $handler = static::getContainer()->get(ExecuteQueryHandler::class);
        $handler->__invoke(new ExecuteQuery($queryId, $queryRequest, (string) $user->getId(), $organizationId));

        $state = $resultStore->getQueryState($queryId);
        $this->assertSame('completed', $state['status']);
        $this->assertGreaterThan(0, $state['result']['total'], 'Should find at least 1 histogram metric');
    }

    public function testMultipleMetricsFromSameService(): void
    {
        // Test that multiple different metrics from the same service are all stored correctly
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();
        $projectId = $project->getPublicId()->toRfc4122();

        $now = (int) (microtime(true) * 1_000_000_000);
        $serviceName = 'multi-metric-service';

        // Ingest multiple metrics
        $metrics = [
            ['name' => 'cpu.usage', 'value' => 45.2, 'unit' => '%'],
            ['name' => 'memory.used', 'value' => 1024.0, 'unit' => 'MB'],
            ['name' => 'disk.free', 'value' => 50000.0, 'unit' => 'MB'],
        ];

        foreach ($metrics as $i => $m) {
            $payload = $this->createOtlpGaugeMetricPayload($serviceName, $m['name'], $m['value'], $m['unit'], $now + $i * 1000);
            $this->browser()
                ->post('/v1/metrics', HttpOptions::json($payload)
                    ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
                ->assertStatus(200);
        }

        // Verify all messages were dispatched - ProcessEvent has all fields
        $messages = $this->transport('async_events')->queue()->messages(ProcessEvent::class);
        $this->assertCount(3, $messages);

        // Verify each metric has correct attributes
        $metricNames = [];
        $metricValues = [];
        $metricUnits = [];
        foreach ($messages as $msg) {
            $this->assertSame('metric', $msg->eventData['event_type']);
            $this->assertSame($serviceName, $msg->eventData['bundle_id']);
            $metricNames[] = $msg->eventData['metric_name'];
            $metricValues[$msg->eventData['metric_name']] = $msg->eventData['metric_value'];
            $metricUnits[$msg->eventData['metric_name']] = $msg->eventData['metric_unit'];
        }

        $this->assertContains('cpu.usage', $metricNames);
        $this->assertContains('memory.used', $metricNames);
        $this->assertContains('disk.free', $metricNames);

        $this->assertSame(45.2, $metricValues['cpu.usage']);
        $this->assertSame(1024.0, $metricValues['memory.used']);
        $this->assertSame(50000.0, $metricValues['disk.free']);

        $this->assertSame('%', $metricUnits['cpu.usage']);
        $this->assertSame('MB', $metricUnits['memory.used']);
        $this->assertSame('MB', $metricUnits['disk.free']);

        // Process all
        $this->transport('async_events')->process(3);

        // Query and verify all metrics are retrievable
        /** @var AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(AsyncQueryResultStore::class);
        $queryId = Uuid::v7()->toRfc4122();

        $queryTime = new \DateTimeImmutable();
        $queryRequest = [
            'filters' => [
                ['attribute' => 'event_type', 'operator' => 'eq', 'value' => 'metric'],
            ],
            'groupBy' => null,
            'page' => 1,
            'limit' => 50,
            'projectId' => $projectId,
            'startDate' => $queryTime->modify('-1 hour')->format('c'),
            'endDate' => $queryTime->modify('+1 hour')->format('c'),
        ];

        $resultStore->initializeQuery($queryId, $queryRequest, (string) $user->getId(), $organizationId);

        /** @var ExecuteQueryHandler $handler */
        $handler = static::getContainer()->get(ExecuteQueryHandler::class);
        $handler->__invoke(new ExecuteQuery($queryId, $queryRequest, (string) $user->getId(), $organizationId));

        $state = $resultStore->getQueryState($queryId);
        $this->assertSame('completed', $state['status']);
        $this->assertSame(3, $state['result']['total'], 'Should find all 3 metrics');
    }

    /**
     * Create a valid OTLP gauge metric payload with configurable timestamp.
     *
     * @return array<string, mixed>
     */
    private function createOtlpGaugeMetricPayload(string $serviceName, string $metricName, float $value, string $unit, int $timestampNanos): array
    {
        return [
            'resourceMetrics' => [
                [
                    'resource' => [
                        'attributes' => [
                            [
                                'key' => 'service.name',
                                'value' => ['stringValue' => $serviceName],
                            ],
                            [
                                'key' => 'service.version',
                                'value' => ['stringValue' => '1.0.0'],
                            ],
                        ],
                    ],
                    'scopeMetrics' => [
                        [
                            'scope' => [
                                'name' => 'test-scope',
                                'version' => '1.0.0',
                            ],
                            'metrics' => [
                                [
                                    'name' => $metricName,
                                    'description' => 'Test gauge metric',
                                    'unit' => $unit,
                                    'gauge' => [
                                        'dataPoints' => [
                                            [
                                                'timeUnixNano' => (string) $timestampNanos,
                                                'asDouble' => $value,
                                                'attributes' => [
                                                    [
                                                        'key' => 'http.method',
                                                        'value' => ['stringValue' => 'GET'],
                                                    ],
                                                    [
                                                        'key' => 'http.route',
                                                        'value' => ['stringValue' => '/api/test'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Create a valid OTLP counter (sum) metric payload.
     *
     * @return array<string, mixed>
     */
    private function createOtlpCounterMetricPayload(string $serviceName, string $metricName, int $value, int $timestampNanos): array
    {
        return [
            'resourceMetrics' => [
                [
                    'resource' => [
                        'attributes' => [
                            [
                                'key' => 'service.name',
                                'value' => ['stringValue' => $serviceName],
                            ],
                        ],
                    ],
                    'scopeMetrics' => [
                        [
                            'scope' => [
                                'name' => 'test-scope',
                            ],
                            'metrics' => [
                                [
                                    'name' => $metricName,
                                    'description' => 'Test counter metric',
                                    'unit' => '1',
                                    'sum' => [
                                        'dataPoints' => [
                                            [
                                                'timeUnixNano' => (string) $timestampNanos,
                                                'asInt' => (string) $value,
                                                'attributes' => [
                                                    [
                                                        'key' => 'http.method',
                                                        'value' => ['stringValue' => 'POST'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'aggregationTemporality' => 2, // CUMULATIVE
                                        'isMonotonic' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Create a valid OTLP histogram metric payload.
     *
     * @return array<string, mixed>
     */
    private function createOtlpHistogramMetricPayload(string $serviceName, string $metricName, int $timestampNanos): array
    {
        return [
            'resourceMetrics' => [
                [
                    'resource' => [
                        'attributes' => [
                            [
                                'key' => 'service.name',
                                'value' => ['stringValue' => $serviceName],
                            ],
                        ],
                    ],
                    'scopeMetrics' => [
                        [
                            'scope' => [
                                'name' => 'test-scope',
                            ],
                            'metrics' => [
                                [
                                    'name' => $metricName,
                                    'description' => 'Test histogram metric',
                                    'unit' => 'ms',
                                    'histogram' => [
                                        'dataPoints' => [
                                            [
                                                'timeUnixNano' => (string) $timestampNanos,
                                                'count' => '10',
                                                'sum' => 1500.0,
                                                'bucketCounts' => ['2', '3', '3', '2'],
                                                'explicitBounds' => [50.0, 100.0, 200.0],
                                                'min' => 25.0,
                                                'max' => 350.0,
                                                'attributes' => [
                                                    [
                                                        'key' => 'http.method',
                                                        'value' => ['stringValue' => 'GET'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'aggregationTemporality' => 2, // CUMULATIVE
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
