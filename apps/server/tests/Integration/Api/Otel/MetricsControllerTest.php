<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api\Otel;

use App\Message\ProcessEvent;
use App\Tests\Integration\AbstractIntegrationTestCase;
use Zenstruck\Messenger\Test\InteractsWithMessenger;

class MetricsControllerTest extends AbstractIntegrationTestCase
{
    use InteractsWithMessenger;

    public function testValidMetricReturns200(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $payload = $this->createValidOtlpMetricPayload();

        $this->client->request('POST', '/v1/metrics', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ERRATA_KEY' => $apiKeyData['plainKey'],
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(200);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('partialSuccess', $response);
    }

    public function testMissingAuthReturns401(): void
    {
        $this->client->request('POST', '/v1/metrics', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testInvalidJsonReturns400(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $this->client->request('POST', '/v1/metrics', [], [], [
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

        $this->client->request('POST', '/v1/metrics', [], [], [
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

        $payload = $this->createValidOtlpMetricPayload();

        $this->client->request('POST', '/v1/metrics', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ERRATA_KEY' => $apiKeyData['plainKey'],
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(200);

        $this->transport('async')
            ->queue()
            ->assertContains(ProcessEvent::class, 1);

        $messages = $this->transport('async')->queue()->messages(ProcessEvent::class);
        $this->assertSame('metric', $messages[0]->eventData['event_type']);
        $this->assertSame('http.request.duration', $messages[0]->eventData['metric_name']);
        $this->assertSame(150.5, $messages[0]->eventData['metric_value']);
    }

    /**
     * @return array<string, mixed>
     */
    private function createValidOtlpMetricPayload(): array
    {
        return [
            'resourceMetrics' => [
                [
                    'resource' => [
                        'attributes' => [
                            [
                                'key' => 'service.name',
                                'value' => ['stringValue' => 'test-service'],
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
                                    'name' => 'http.request.duration',
                                    'description' => 'HTTP request duration in milliseconds',
                                    'unit' => 'ms',
                                    'gauge' => [
                                        'dataPoints' => [
                                            [
                                                'timeUnixNano' => '1000000000000',
                                                'asDouble' => 150.5,
                                                'attributes' => [
                                                    [
                                                        'key' => 'http.method',
                                                        'value' => ['stringValue' => 'GET'],
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
}
