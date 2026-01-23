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
 * End-to-end test for the complete trace ingestion → parquet write → query flow.
 *
 * This test proves:
 * 1. OTLP trace ingestion works via /v1/traces
 * 2. ProcessEvent handler writes to parquet with all attributes
 * 3. Query execution can read the data back
 * 4. Timeframe filtering works correctly
 * 5. Filtered queries return matching results
 */
class TraceIngestionQueryTest extends AbstractIntegrationTestCase
{
    use InteractsWithMessenger;

    public function testFullTraceIngestionAndQueryFlow(): void
    {
        // 1. Setup: Create project and API key
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();
        $projectId = $project->getPublicId()->toRfc4122();

        // 2. Ingest: Send OTLP trace with a recent timestamp
        $now = (int) (microtime(true) * 1_000_000_000); // nanoseconds
        $traceData = $this->createOtlpTracePayload('test-service', 'test-operation', $now);

        $this->browser()
            ->post('/v1/traces', HttpOptions::json($traceData['payload'])
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
        $this->assertSame('span', $eventData['event_type'], 'ProcessEvent should have event_type=span');
        $this->assertSame($traceData['traceId'], $eventData['trace_id'], 'ProcessEvent should have correct trace_id');
        $this->assertSame($traceData['spanId'], $eventData['span_id'], 'ProcessEvent should have correct span_id');
        $this->assertSame('test-operation', $eventData['operation'], 'ProcessEvent should have correct operation');
        $this->assertSame('test-service', $eventData['bundle_id'], 'ProcessEvent should have correct bundle_id (service.name)');
        $this->assertSame('ok', $eventData['span_status'], 'ProcessEvent should have correct span_status');
        $this->assertEqualsWithDelta(100.0, $eventData['duration_ms'], 0.1, 'ProcessEvent should have correct duration_ms');
        $this->assertNull($eventData['parent_span_id'], 'Root span should have no parent_span_id');

        // 3. Process: Handle the ProcessEvent message (writes to parquet buffer)
        $this->transport('async_events')->process(1);
        $this->flushParquetBuffer();

        // Verify parquet files exist after processing
        /** @var StorageFactory $storageFactory */
        $storageFactory = static::getContainer()->get(StorageFactory::class);
        $basePath = $storageFactory->getLocalPath();

        $parquetFiles = glob($basePath.'/organization_id='.$organizationId.'/project_id='.$projectId.'/event_type=*/dt=*/*.parquet');
        $this->assertNotEmpty($parquetFiles, 'Parquet files should exist after processing');

        // 4. Query: Execute async query for the ingested trace
        /** @var AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(AsyncQueryResultStore::class);
        $queryId = Uuid::v7()->toRfc4122();

        $queryTime = new \DateTimeImmutable();
        $queryRequest = [
            'filters' => [
                ['attribute' => 'event_type', 'operator' => 'eq', 'value' => 'span'],
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
        $this->assertGreaterThan(0, $state['result']['total'], 'Should find at least 1 span');

        // Verify the span data (note: async query returns subset of columns based on filters)
        $events = $state['result']['events'];
        $this->assertNotEmpty($events, 'Should have at least one event');
        $this->assertSame('span', $events[0]['event_type'], 'Event type should be span');
        $this->assertArrayHasKey('timestamp', $events[0], 'Should have timestamp');
        $this->assertArrayHasKey('event_id', $events[0], 'Should have event_id');
    }

    public function testQueryWithTraceIdFilter(): void
    {
        // Test that filtering by trace_id includes that column in results
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();
        $projectId = $project->getPublicId()->toRfc4122();

        $now = (int) (microtime(true) * 1_000_000_000);
        $traceData = $this->createOtlpTracePayload('trace-filter-service', 'trace-filter-op', $now);

        $this->browser()
            ->post('/v1/traces', HttpOptions::json($traceData['payload'])
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);

        // Verify ProcessEvent has all the data
        $messages = $this->transport('async_events')->queue()->messages(ProcessEvent::class);
        $this->assertSame($traceData['traceId'], $messages[0]->eventData['trace_id']);
        $this->assertSame($traceData['spanId'], $messages[0]->eventData['span_id']);

        $this->transport('async_events')->process(1);
        $this->flushParquetBuffer();

        // Query with trace_id filter - this will include trace_id in returned columns
        /** @var AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(AsyncQueryResultStore::class);
        $queryId = Uuid::v7()->toRfc4122();

        $queryTime = new \DateTimeImmutable();
        $queryRequest = [
            'filters' => [
                ['attribute' => 'event_type', 'operator' => 'eq', 'value' => 'span'],
                ['attribute' => 'trace_id', 'operator' => 'eq', 'value' => $traceData['traceId']],
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
        $this->assertSame(1, $state['result']['total'], 'Should find exactly 1 span with this trace_id');

        // When trace_id is in filter, it's returned in results
        $span = $state['result']['events'][0];
        $this->assertSame($traceData['traceId'], $span['trace_id'], 'Query result should have correct trace_id');
    }

    public function testSpanWithParentSpanId(): void
    {
        // 1. Setup
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();
        $projectId = $project->getPublicId()->toRfc4122();

        // 2. Ingest: Send OTLP trace with parent-child spans
        $now = (int) (microtime(true) * 1_000_000_000);
        $traceData = $this->createOtlpTracePayloadWithParent('parent-child-service', $now);

        $this->browser()
            ->post('/v1/traces', HttpOptions::json($traceData['payload'])
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);

        // Verify both spans were dispatched
        $this->transport('async_events')
            ->queue()
            ->assertContains(ProcessEvent::class, 2);

        $messages = $this->transport('async_events')->queue()->messages(ProcessEvent::class);

        // Find parent and child spans
        $parentSpan = null;
        $childSpan = null;
        foreach ($messages as $msg) {
            if ($msg->eventData['span_id'] === $traceData['parentSpanId']) {
                $parentSpan = $msg->eventData;
            }
            if ($msg->eventData['span_id'] === $traceData['childSpanId']) {
                $childSpan = $msg->eventData;
            }
        }

        $this->assertNotNull($parentSpan, 'Parent span should exist');
        $this->assertNotNull($childSpan, 'Child span should exist');

        // Verify parent span attributes
        $this->assertSame($traceData['traceId'], $parentSpan['trace_id'], 'Parent should have correct trace_id');
        $this->assertSame($traceData['parentSpanId'], $parentSpan['span_id'], 'Parent should have correct span_id');
        $this->assertNull($parentSpan['parent_span_id'], 'Parent span should have no parent_span_id');
        $this->assertSame('parent-operation', $parentSpan['operation'], 'Parent should have correct operation');
        $this->assertSame('parent-child-service', $parentSpan['bundle_id'], 'Parent should have correct bundle_id');
        $this->assertEqualsWithDelta(200.0, $parentSpan['duration_ms'], 0.1, 'Parent should have correct duration');

        // Verify child span attributes
        $this->assertSame($traceData['traceId'], $childSpan['trace_id'], 'Child should have same trace_id');
        $this->assertSame($traceData['childSpanId'], $childSpan['span_id'], 'Child should have correct span_id');
        $this->assertSame($traceData['parentSpanId'], $childSpan['parent_span_id'], 'Child span should reference parent');
        $this->assertSame('child-operation', $childSpan['operation'], 'Child should have correct operation');
        $this->assertEqualsWithDelta(140.0, $childSpan['duration_ms'], 0.1, 'Child should have correct duration');

        // 3. Process
        $this->transport('async_events')->process(2);
        $this->flushParquetBuffer();

        // 4. Query and verify
        /** @var AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(AsyncQueryResultStore::class);
        $queryId = Uuid::v7()->toRfc4122();

        $queryTime = new \DateTimeImmutable();
        $queryRequest = [
            'filters' => [
                ['attribute' => 'event_type', 'operator' => 'eq', 'value' => 'span'],
                ['attribute' => 'trace_id', 'operator' => 'eq', 'value' => $traceData['traceId']],
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
        $this->assertSame(2, $state['result']['total'], 'Should find exactly 2 spans in the trace');
    }

    public function testQueryWithEmptyTimeframeReturnsNoResults(): void
    {
        // 1. Setup: Create project and API key
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();

        // 2. Ingest: Send OTLP trace with a timestamp from the past
        $pastTime = (int) ((time() - 86400 * 30) * 1_000_000_000); // 30 days ago in nanoseconds
        $traceData = $this->createOtlpTracePayload('old-service', 'old-operation', $pastTime);

        $this->browser()
            ->post('/v1/traces', HttpOptions::json($traceData['payload'])
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);

        // 3. Process: Handle the ProcessEvent message
        $this->transport('async_events')->process(1);
        $this->flushParquetBuffer();

        // 4. Query: Execute query with a timeframe that doesn't include our trace
        /** @var AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(AsyncQueryResultStore::class);
        $queryId = Uuid::v7()->toRfc4122();

        $now = new \DateTimeImmutable();
        $queryRequest = [
            'filters' => [
                ['attribute' => 'event_type', 'operator' => 'eq', 'value' => 'span'],
            ],
            'groupBy' => null,
            'page' => 1,
            'limit' => 50,
            'projectId' => $project->getPublicId()->toRfc4122(),
            // Query for last hour only - should not find the 30-day-old trace
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
        $this->assertSame(0, $state['result']['total'], 'Should find no spans in recent timeframe');
    }

    /**
     * Create a valid OTLP trace payload with configurable timestamp.
     *
     * @return array{payload: array<string, mixed>, traceId: string, spanId: string}
     */
    private function createOtlpTracePayload(string $serviceName, string $operation, int $timestampNanos): array
    {
        $traceId = bin2hex(random_bytes(16));
        $spanId = bin2hex(random_bytes(8));

        $payload = [
            'resourceSpans' => [
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
                    'scopeSpans' => [
                        [
                            'scope' => [
                                'name' => 'test-scope',
                                'version' => '1.0.0',
                            ],
                            'spans' => [
                                [
                                    'traceId' => base64_encode(hex2bin($traceId)),
                                    'spanId' => base64_encode(hex2bin($spanId)),
                                    'name' => $operation,
                                    'kind' => 2, // SPAN_KIND_SERVER
                                    'startTimeUnixNano' => (string) $timestampNanos,
                                    'endTimeUnixNano' => (string) ($timestampNanos + 100_000_000), // 100ms duration
                                    'attributes' => [
                                        [
                                            'key' => 'http.method',
                                            'value' => ['stringValue' => 'GET'],
                                        ],
                                        [
                                            'key' => 'http.url',
                                            'value' => ['stringValue' => '/api/test'],
                                        ],
                                    ],
                                    'status' => [
                                        'code' => 1, // STATUS_CODE_OK
                                        'message' => '',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return [
            'payload' => $payload,
            'traceId' => $traceId,
            'spanId' => $spanId,
        ];
    }

    /**
     * Create an OTLP trace payload with parent-child span relationship.
     *
     * @return array{payload: array<string, mixed>, traceId: string, parentSpanId: string, childSpanId: string}
     */
    private function createOtlpTracePayloadWithParent(string $serviceName, int $timestampNanos): array
    {
        $traceId = bin2hex(random_bytes(16));
        $parentSpanId = bin2hex(random_bytes(8));
        $childSpanId = bin2hex(random_bytes(8));

        $payload = [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => [
                            [
                                'key' => 'service.name',
                                'value' => ['stringValue' => $serviceName],
                            ],
                        ],
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => [
                                'name' => 'test-scope',
                            ],
                            'spans' => [
                                // Parent span
                                [
                                    'traceId' => base64_encode(hex2bin($traceId)),
                                    'spanId' => base64_encode(hex2bin($parentSpanId)),
                                    'name' => 'parent-operation',
                                    'kind' => 2, // SPAN_KIND_SERVER
                                    'startTimeUnixNano' => (string) $timestampNanos,
                                    'endTimeUnixNano' => (string) ($timestampNanos + 200_000_000), // 200ms
                                    'attributes' => [],
                                    'status' => ['code' => 1],
                                ],
                                // Child span
                                [
                                    'traceId' => base64_encode(hex2bin($traceId)),
                                    'spanId' => base64_encode(hex2bin($childSpanId)),
                                    'parentSpanId' => base64_encode(hex2bin($parentSpanId)),
                                    'name' => 'child-operation',
                                    'kind' => 3, // SPAN_KIND_CLIENT
                                    'startTimeUnixNano' => (string) ($timestampNanos + 10_000_000), // 10ms after parent
                                    'endTimeUnixNano' => (string) ($timestampNanos + 150_000_000), // 140ms duration
                                    'attributes' => [],
                                    'status' => ['code' => 1],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return [
            'payload' => $payload,
            'traceId' => $traceId,
            'parentSpanId' => $parentSpanId,
            'childSpanId' => $childSpanId,
        ];
    }
}
