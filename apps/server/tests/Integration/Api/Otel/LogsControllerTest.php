<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api\Otel;

use App\Message\ProcessEvent;
use App\Tests\Integration\AbstractIntegrationTestCase;
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

        $this->client->request('POST', '/v1/logs', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ERRATA_KEY' => $apiKeyData['plainKey'],
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(200);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('partialSuccess', $response);
    }

    public function testMissingAuthReturns401(): void
    {
        $this->client->request('POST', '/v1/logs', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testInvalidJsonReturns400(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $this->client->request('POST', '/v1/logs', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ERRATA_KEY' => $apiKeyData['plainKey'],
        ], 'invalid json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testProtobufContentTypeReturns415(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $this->client->request('POST', '/v1/logs', [], [], [
            'CONTENT_TYPE' => 'application/x-protobuf',
            'HTTP_X_ERRATA_KEY' => $apiKeyData['plainKey'],
        ], '');

        $this->assertResponseStatusCodeSame(415);
    }

    public function testDispatchesProcessEventMessage(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $payload = $this->createValidOtlpLogPayload();

        $this->client->request('POST', '/v1/logs', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ERRATA_KEY' => $apiKeyData['plainKey'],
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(200);

        $this->transport('async')
            ->queue()
            ->assertContains(ProcessEvent::class, 1);

        $messages = $this->transport('async')->queue()->messages(ProcessEvent::class);
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

        $this->client->request('POST', '/v1/logs', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ERRATA_KEY' => $apiKeyData['plainKey'],
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(200);

        $this->transport('async')
            ->queue()
            ->assertContains(ProcessEvent::class, 1);

        $messages = $this->transport('async')->queue()->messages(ProcessEvent::class);
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
