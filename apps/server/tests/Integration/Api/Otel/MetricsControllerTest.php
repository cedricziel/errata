<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api\Otel;

use App\Message\ProcessEvent;
use App\Tests\Integration\AbstractIntegrationTestCase;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use Zenstruck\Browser\HttpOptions;
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

        $this->browser()
            ->post('/v1/metrics', HttpOptions::json($payload)
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
            ->post('/v1/metrics', HttpOptions::json([]))
            ->assertStatus(401);
    }

    public function testInvalidJsonReturns400(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $this->browser()
            ->post('/v1/metrics', HttpOptions::create()
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

        $request = new ExportMetricsServiceRequest();
        $request->mergeFromJsonString(json_encode($this->createValidOtlpMetricPayload()));
        $protobufData = $request->serializeToString();

        $this->browser()
            ->post('/v1/metrics', HttpOptions::create()
                ->withHeader('Content-Type', 'application/x-protobuf')
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey'])
                ->withBody($protobufData))
            ->assertStatus(200);

        $this->transport('async_events')
            ->queue()
            ->assertContains(ProcessEvent::class, 1);

        $messages = $this->transport('async_events')->queue()->messages(ProcessEvent::class);
        $this->assertSame('metric', $messages[0]->eventData['event_type']);
        $this->assertSame('http.request.duration', $messages[0]->eventData['metric_name']);
    }

    public function testDispatchesProcessEventMessage(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $payload = $this->createValidOtlpMetricPayload();

        $this->browser()
            ->post('/v1/metrics', HttpOptions::json($payload)
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);

        $this->transport('async_events')
            ->queue()
            ->assertContains(ProcessEvent::class, 1);

        $messages = $this->transport('async_events')->queue()->messages(ProcessEvent::class);
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
