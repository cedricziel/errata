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
 * End-to-end test for the complete log ingestion → parquet write → query flow.
 *
 * This test proves:
 * 1. OTLP log ingestion works via /v1/logs
 * 2. ProcessEvent handler writes to parquet with all attributes
 * 3. Query execution can read the data back
 * 4. Timeframe filtering works correctly
 * 5. Log severity levels are correctly mapped
 */
class LogIngestionQueryTest extends AbstractIntegrationTestCase
{
    use InteractsWithMessenger;

    public function testFullLogIngestionAndQueryFlow(): void
    {
        // 1. Setup: Create project and API key
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();
        $projectId = $project->getPublicId()->toRfc4122();

        // 2. Ingest: Send OTLP log with a recent timestamp
        $now = (int) (microtime(true) * 1_000_000_000); // nanoseconds
        $testMessage = 'Test log message from E2E test - '.bin2hex(random_bytes(4));
        $logPayload = $this->createOtlpLogPayload('test-log-service', $testMessage, 'ERROR', 17, $now);

        $this->browser()
            ->post('/v1/logs', HttpOptions::json($logPayload)
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
        $this->assertSame('log', $eventData['event_type'], 'ProcessEvent should have event_type=log');
        $this->assertSame($testMessage, $eventData['message'], 'ProcessEvent should have correct message');
        $this->assertSame('error', $eventData['severity'], 'ProcessEvent should have correct severity');
        $this->assertSame('test-log-service', $eventData['bundle_id'], 'ProcessEvent should have correct bundle_id (service.name)');

        // 3. Process: Handle the ProcessEvent message (writes to parquet buffer)
        $this->transport('async_events')->process(1);

        // Flush parquet buffer to write events to disk
        $this->flushParquetBuffer();

        // Verify parquet files exist after processing
        /** @var StorageFactory $storageFactory */
        $storageFactory = static::getContainer()->get(StorageFactory::class);
        $basePath = $storageFactory->getLocalPath();

        $parquetFiles = glob($basePath.'/organization_id='.$organizationId.'/project_id='.$projectId.'/event_type=*/dt=*/*.parquet');
        $this->assertNotEmpty($parquetFiles, 'Parquet files should exist after processing');

        // 4. Query: Execute async query for the ingested log
        /** @var AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(AsyncQueryResultStore::class);
        $queryId = Uuid::v7()->toRfc4122();

        $queryTime = new \DateTimeImmutable();
        $queryRequest = [
            'filters' => [
                ['attribute' => 'event_type', 'operator' => 'eq', 'value' => 'log'],
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
        $this->assertGreaterThan(0, $state['result']['total'], 'Should find at least 1 log');

        // Verify the log data (note: async query returns subset of columns based on filters)
        $events = $state['result']['events'];
        $this->assertNotEmpty($events, 'Should have at least one event');
        $this->assertSame('log', $events[0]['event_type'], 'Event type should be log');
        $this->assertArrayHasKey('timestamp', $events[0], 'Should have timestamp');
        $this->assertArrayHasKey('event_id', $events[0], 'Should have event_id');
    }

    public function testLogSeverityLevels(): void
    {
        // Test different severity levels are correctly mapped
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();
        $projectId = $project->getPublicId()->toRfc4122();

        $now = (int) (microtime(true) * 1_000_000_000);

        // Test INFO level (severityNumber 9)
        $infoPayload = $this->createOtlpLogPayload('severity-test', 'Info message', 'INFO', 9, $now);
        $this->browser()
            ->post('/v1/logs', HttpOptions::json($infoPayload)
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);

        // Test WARN level (severityNumber 13)
        $warnPayload = $this->createOtlpLogPayload('severity-test', 'Warn message', 'WARN', 13, $now + 1000);
        $this->browser()
            ->post('/v1/logs', HttpOptions::json($warnPayload)
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);

        // Test DEBUG level (severityNumber 5)
        $debugPayload = $this->createOtlpLogPayload('severity-test', 'Debug message', 'DEBUG', 5, $now + 2000);
        $this->browser()
            ->post('/v1/logs', HttpOptions::json($debugPayload)
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);

        // Verify all messages have correct severity - ProcessEvent has all the data
        $messages = $this->transport('async_events')->queue()->messages(ProcessEvent::class);
        $this->assertCount(3, $messages);

        $severities = array_map(fn ($m) => $m->eventData['severity'], $messages);
        $this->assertContains('info', $severities, 'Should have info severity');
        $this->assertContains('warn', $severities, 'Should have warn severity (OTLP severityText is used as-is)');
        $this->assertContains('debug', $severities, 'Should have debug severity');

        // Also verify each message individually
        foreach ($messages as $msg) {
            $this->assertSame('log', $msg->eventData['event_type']);
            $this->assertSame('severity-test', $msg->eventData['bundle_id']);
            $this->assertNotEmpty($msg->eventData['message']);
        }

        // Process all
        $this->transport('async_events')->process(3);
        $this->flushParquetBuffer();

        // Query and verify all logs are found
        /** @var AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(AsyncQueryResultStore::class);
        $queryId = Uuid::v7()->toRfc4122();

        $queryTime = new \DateTimeImmutable();
        $queryRequest = [
            'filters' => [
                ['attribute' => 'event_type', 'operator' => 'eq', 'value' => 'log'],
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
        $this->assertSame(3, $state['result']['total'], 'Should find all 3 logs');
    }

    public function testLogQueryWithEmptyTimeframeReturnsNoResults(): void
    {
        // 1. Setup: Create project and API key
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();

        // 2. Ingest: Send OTLP log with a timestamp from the past
        $pastTime = (int) ((time() - 86400 * 30) * 1_000_000_000); // 30 days ago in nanoseconds
        $logPayload = $this->createOtlpLogPayload('old-service', 'Old log message', 'ERROR', 17, $pastTime);

        $this->browser()
            ->post('/v1/logs', HttpOptions::json($logPayload)
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);

        // 3. Process: Handle the ProcessEvent message
        $this->transport('async_events')->process(1);
        $this->flushParquetBuffer();

        // 4. Query: Execute query with a timeframe that doesn't include our log
        /** @var AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(AsyncQueryResultStore::class);
        $queryId = Uuid::v7()->toRfc4122();

        $now = new \DateTimeImmutable();
        $queryRequest = [
            'filters' => [
                ['attribute' => 'event_type', 'operator' => 'eq', 'value' => 'log'],
            ],
            'groupBy' => null,
            'page' => 1,
            'limit' => 50,
            'projectId' => $project->getPublicId()->toRfc4122(),
            // Query for last hour only - should not find the 30-day-old log
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
        $this->assertSame(0, $state['result']['total'], 'Should find no logs in recent timeframe');
    }

    public function testLogWithTraceContextIsIngested(): void
    {
        // 1. Setup: Create project and API key
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();
        $projectId = $project->getPublicId()->toRfc4122();

        // 2. Ingest: Send OTLP log with trace context
        $now = (int) (microtime(true) * 1_000_000_000);
        $traceId = bin2hex(random_bytes(16));
        $spanId = bin2hex(random_bytes(8));
        $testMessage = 'Log with trace context - '.bin2hex(random_bytes(4));
        $logPayload = $this->createOtlpLogPayloadWithTraceContext('traced-service', $testMessage, $now, $traceId, $spanId);

        $this->browser()
            ->post('/v1/logs', HttpOptions::json($logPayload)
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);

        // Verify the message contains trace context - ProcessEvent has all fields
        $messages = $this->transport('async_events')->queue()->messages(ProcessEvent::class);
        $this->assertCount(1, $messages);

        $eventData = $messages[0]->eventData;
        $this->assertSame('log', $eventData['event_type'], 'Should be a log event');
        $this->assertSame($traceId, $eventData['trace_id'], 'Log should have correct trace_id');
        $this->assertSame($spanId, $eventData['span_id'], 'Log should have correct span_id');
        $this->assertSame($testMessage, $eventData['message'], 'Log should have correct message');
        $this->assertSame('traced-service', $eventData['bundle_id'], 'Log should have correct bundle_id');
        $this->assertSame('info', $eventData['severity'], 'Log should have correct severity');

        // 3. Process: Handle the ProcessEvent message
        $this->transport('async_events')->process(1);
        $this->flushParquetBuffer();

        // 4. Query: Execute async query with trace_id filter to get that column in results
        /** @var AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(AsyncQueryResultStore::class);
        $queryId = Uuid::v7()->toRfc4122();

        $queryTime = new \DateTimeImmutable();
        $queryRequest = [
            'filters' => [
                ['attribute' => 'event_type', 'operator' => 'eq', 'value' => 'log'],
                ['attribute' => 'trace_id', 'operator' => 'eq', 'value' => $traceId],
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

        // 5. Verify: Check query results with trace context filter
        $state = $resultStore->getQueryState($queryId);
        $this->assertSame('completed', $state['status']);
        $this->assertSame(1, $state['result']['total'], 'Should find exactly 1 log with this trace_id');

        // When trace_id is in filter, it's included in results
        $log = $state['result']['events'][0];
        $this->assertSame($traceId, $log['trace_id'], 'Query result should have correct trace_id');
    }

    /**
     * Create a valid OTLP log payload with configurable timestamp.
     *
     * @return array<string, mixed>
     */
    private function createOtlpLogPayload(string $serviceName, string $message, string $severityText, int $severityNumber, int $timestampNanos): array
    {
        return [
            'resourceLogs' => [
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
                    'scopeLogs' => [
                        [
                            'scope' => [
                                'name' => 'test-scope',
                                'version' => '1.0.0',
                            ],
                            'logRecords' => [
                                [
                                    'timeUnixNano' => (string) $timestampNanos,
                                    'severityNumber' => $severityNumber,
                                    'severityText' => $severityText,
                                    'body' => [
                                        'stringValue' => $message,
                                    ],
                                    'attributes' => [
                                        [
                                            'key' => 'request.id',
                                            'value' => ['stringValue' => 'req-'.bin2hex(random_bytes(4))],
                                        ],
                                        [
                                            'key' => 'user.id',
                                            'value' => ['stringValue' => 'user-123'],
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
     * Create an OTLP log payload with trace context.
     *
     * @return array<string, mixed>
     */
    private function createOtlpLogPayloadWithTraceContext(
        string $serviceName,
        string $message,
        int $timestampNanos,
        string $traceId,
        string $spanId,
    ): array {
        return [
            'resourceLogs' => [
                [
                    'resource' => [
                        'attributes' => [
                            [
                                'key' => 'service.name',
                                'value' => ['stringValue' => $serviceName],
                            ],
                        ],
                    ],
                    'scopeLogs' => [
                        [
                            'scope' => [
                                'name' => 'test-scope',
                            ],
                            'logRecords' => [
                                [
                                    'timeUnixNano' => (string) $timestampNanos,
                                    'severityNumber' => 9, // INFO
                                    'severityText' => 'INFO',
                                    'body' => [
                                        'stringValue' => $message,
                                    ],
                                    'traceId' => base64_encode(hex2bin($traceId)),
                                    'spanId' => base64_encode(hex2bin($spanId)),
                                    'attributes' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
