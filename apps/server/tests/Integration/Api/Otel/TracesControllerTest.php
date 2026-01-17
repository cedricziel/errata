<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api\Otel;

use App\Message\ProcessEvent;
use App\Tests\Integration\AbstractIntegrationTestCase;
use Zenstruck\Messenger\Test\InteractsWithMessenger;

class TracesControllerTest extends AbstractIntegrationTestCase
{
    use InteractsWithMessenger;

    public function testValidTraceReturns200(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $payload = $this->createValidOtlpTracePayload();

        $this->client->request('POST', '/v1/traces', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ERRATA_KEY' => $apiKeyData['plainKey'],
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(200);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('partialSuccess', $response);
    }

    public function testMissingAuthReturns401(): void
    {
        $this->client->request('POST', '/v1/traces', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testInvalidAuthReturns401(): void
    {
        $this->client->request('POST', '/v1/traces', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ERRATA_KEY' => 'invalid-key',
        ], '{}');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testInvalidJsonReturns400(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $this->client->request('POST', '/v1/traces', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ERRATA_KEY' => $apiKeyData['plainKey'],
        ], 'invalid json');

        $this->assertResponseStatusCodeSame(400);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('bad_request', $response['error']);
    }

    public function testProtobufContentTypeReturns415(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $this->client->request('POST', '/v1/traces', [], [], [
            'CONTENT_TYPE' => 'application/x-protobuf',
            'HTTP_X_ERRATA_KEY' => $apiKeyData['plainKey'],
        ], '');

        $this->assertResponseStatusCodeSame(415);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('unsupported_media_type', $response['error']);
    }

    public function testDispatchesProcessEventMessage(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $payload = $this->createValidOtlpTracePayload();

        $this->client->request('POST', '/v1/traces', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ERRATA_KEY' => $apiKeyData['plainKey'],
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(200);

        $this->transport('async')
            ->queue()
            ->assertContains(ProcessEvent::class, 1);

        $messages = $this->transport('async')->queue()->messages(ProcessEvent::class);
        $this->assertSame('span', $messages[0]->eventData['event_type']);
        $this->assertSame('5b8efff798038103d269b633813fc60c', $messages[0]->eventData['trace_id']);
    }

    public function testEmptyPayloadReturns200(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $payload = ['resourceSpans' => []];

        $this->client->request('POST', '/v1/traces', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ERRATA_KEY' => $apiKeyData['plainKey'],
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(200);
    }

    /**
     * @return array<string, mixed>
     */
    private function createValidOtlpTracePayload(): array
    {
        return [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => [
                            [
                                'key' => 'service.name',
                                'value' => ['stringValue' => 'test-service'],
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
                                    'traceId' => '5b8efff798038103d269b633813fc60c',
                                    'spanId' => '6364652d65373139',
                                    'name' => 'test-operation',
                                    'kind' => 2,
                                    'startTimeUnixNano' => '1000000000000',
                                    'endTimeUnixNano' => '1000100000000',
                                    'attributes' => [
                                        [
                                            'key' => 'http.method',
                                            'value' => ['stringValue' => 'GET'],
                                        ],
                                    ],
                                    'status' => [
                                        'code' => 1,
                                        'message' => '',
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
