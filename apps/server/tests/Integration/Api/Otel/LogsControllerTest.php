<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api\Otel;

use App\Message\ProcessEvent;
use App\Tests\Integration\AbstractIntegrationTestCase;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use Zenstruck\Browser\HttpOptions;
use Zenstruck\Messenger\Test\InteractsWithMessenger;

class LogsControllerTest extends AbstractIntegrationTestCase
{
    use InteractsWithMessenger;

    public function testValidLogReturns200(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $payload = $this->createValidOtlpLogPayload();

        $this->browser()
            ->post('/v1/logs', HttpOptions::json($payload)
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200)
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $this->assertArrayHasKey('partialSuccess', $response);
            });
    }

    public function testMissingAuthReturns401(): void
    {
        $this->browser()
            ->post('/v1/logs', HttpOptions::json([]))
            ->assertStatus(401);
    }

    public function testInvalidJsonReturns400(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $this->browser()
            ->post('/v1/logs', HttpOptions::create()
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey'])
                ->withBody('invalid json'))
            ->assertStatus(400);
    }

    public function testProtobufContentTypeReturns200(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $request = new ExportLogsServiceRequest();
        $request->mergeFromJsonString(json_encode($this->createValidOtlpLogPayload()));
        $protobufData = $request->serializeToString();

        $this->browser()
            ->post('/v1/logs', HttpOptions::create()
                ->withHeader('Content-Type', 'application/x-protobuf')
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey'])
                ->withBody($protobufData))
            ->assertStatus(200);

        $this->transport('async_events')
            ->queue()
            ->assertContains(ProcessEvent::class, 1);

        $messages = $this->transport('async_events')->queue()->messages(ProcessEvent::class);
        $this->assertSame('log', $messages[0]->eventData['event_type']);
        $this->assertSame('Test log message', $messages[0]->eventData['message']);
    }

    public function testDispatchesProcessEventMessage(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $payload = $this->createValidOtlpLogPayload();

        $this->browser()
            ->post('/v1/logs', HttpOptions::json($payload)
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);

        $this->transport('async_events')
            ->queue()
            ->assertContains(ProcessEvent::class, 1);

        $messages = $this->transport('async_events')->queue()->messages(ProcessEvent::class);
        $this->assertSame('log', $messages[0]->eventData['event_type']);
        $this->assertSame('Test log message', $messages[0]->eventData['message']);
        $this->assertSame('error', $messages[0]->eventData['severity']);
    }

    public function testLogWithTraceContext(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $payload = $this->createLogPayloadWithTraceContext();

        $this->browser()
            ->post('/v1/logs', HttpOptions::json($payload)
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);

        $this->transport('async_events')
            ->queue()
            ->assertContains(ProcessEvent::class, 1);

        $messages = $this->transport('async_events')->queue()->messages(ProcessEvent::class);
        $this->assertSame('5b8efff798038103d269b633813fc60c', $messages[0]->eventData['trace_id']);
        $this->assertSame('6364652d65373139', $messages[0]->eventData['span_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function createValidOtlpLogPayload(): array
    {
        return [
            'resourceLogs' => [
                [
                    'resource' => [
                        'attributes' => [
                            [
                                'key' => 'service.name',
                                'value' => ['stringValue' => 'test-service'],
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
                                    'timeUnixNano' => '1000000000000',
                                    'severityNumber' => 17,
                                    'severityText' => 'ERROR',
                                    'body' => [
                                        'stringValue' => 'Test log message',
                                    ],
                                    'attributes' => [
                                        [
                                            'key' => 'request.id',
                                            'value' => ['stringValue' => 'req-123'],
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
     * @return array<string, mixed>
     */
    private function createLogPayloadWithTraceContext(): array
    {
        return [
            'resourceLogs' => [
                [
                    'resource' => [
                        'attributes' => [],
                    ],
                    'scopeLogs' => [
                        [
                            'logRecords' => [
                                [
                                    'timeUnixNano' => '1000000000000',
                                    'severityNumber' => 9,
                                    'body' => [
                                        'stringValue' => 'Log with trace context',
                                    ],
                                    'traceId' => base64_encode(hex2bin('5b8efff798038103d269b633813fc60c')),
                                    'spanId' => base64_encode(hex2bin('6364652d65373139')),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
