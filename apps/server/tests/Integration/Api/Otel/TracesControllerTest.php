<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api\Otel;

use App\Message\ProcessEvent;
use App\Tests\Integration\AbstractIntegrationTestCase;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Zenstruck\Browser\HttpOptions;
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

        $this->browser()
            ->post('/v1/traces', HttpOptions::json($payload)
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
            ->post('/v1/traces', HttpOptions::json([]))
            ->assertStatus(401);
    }

    public function testInvalidAuthReturns401(): void
    {
        $this->browser()
            ->post('/v1/traces', HttpOptions::json([])
                ->withHeader('X-Errata-Key', 'invalid-key'))
            ->assertStatus(401);
    }

    public function testInvalidJsonReturns400(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $this->browser()
            ->post('/v1/traces', HttpOptions::create()
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey'])
                ->withBody('invalid json'))
            ->assertStatus(400)
            ->assertJsonMatches('error', 'bad_request');
    }

    public function testProtobufContentTypeReturns200(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $request = new ExportTraceServiceRequest();
        $request->mergeFromJsonString(json_encode($this->createValidOtlpTracePayload()));
        $protobufData = $request->serializeToString();

        $this->browser()
            ->post('/v1/traces', HttpOptions::create()
                ->withHeader('Content-Type', 'application/x-protobuf')
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey'])
                ->withBody($protobufData))
            ->assertStatus(200);

        $this->transport('async_events')
            ->queue()
            ->assertContains(ProcessEvent::class, 1);

        $messages = $this->transport('async_events')->queue()->messages(ProcessEvent::class);
        $this->assertSame('span', $messages[0]->eventData['event_type']);
        $this->assertSame('5b8efff798038103d269b633813fc60c', $messages[0]->eventData['trace_id']);
    }

    public function testDispatchesProcessEventMessage(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $payload = $this->createValidOtlpTracePayload();

        $this->browser()
            ->post('/v1/traces', HttpOptions::json($payload)
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);

        $this->transport('async_events')
            ->queue()
            ->assertContains(ProcessEvent::class, 1);

        $messages = $this->transport('async_events')->queue()->messages(ProcessEvent::class);
        $this->assertSame('span', $messages[0]->eventData['event_type']);
        $this->assertSame('5b8efff798038103d269b633813fc60c', $messages[0]->eventData['trace_id']);
    }

    public function testEmptyPayloadReturns200(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        $payload = ['resourceSpans' => []];

        $this->browser()
            ->post('/v1/traces', HttpOptions::json($payload)
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(200);
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
                                    'traceId' => base64_encode(hex2bin('5b8efff798038103d269b633813fc60c')),
                                    'spanId' => base64_encode(hex2bin('6364652d65373139')),
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
