<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Otel;

use App\Service\Otel\TraceMapper;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use PHPUnit\Framework\TestCase;

class TraceMapperTest extends TestCase
{
    private TraceMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new TraceMapper();
    }

    public function testMapsSpanToWideEventPayload(): void
    {
        $request = $this->createTraceRequest();

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertCount(1, $events);
        $this->assertSame('span', $events[0]['event_type']);
        $this->assertSame('5b8efff798038103d269b633813fc60c', $events[0]['trace_id']);
        $this->assertSame('test-operation', $events[0]['operation']);
    }

    public function testCalculatesDurationInMilliseconds(): void
    {
        $request = $this->createTraceRequestWithDuration(
            startNano: '1000000000000',  // 1000 seconds
            endNano: '1000100000000'     // 1000.1 seconds = 100ms duration
        );

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertEqualsWithDelta(100.0, $events[0]['duration_ms'], 0.001);
    }

    public function testNormalizesTraceIdToLowercase(): void
    {
        // Hex strings in JSON are already lowercase after bin2hex conversion
        $request = $this->createTraceRequestWithTraceId('5b8efff798038103d269b633813fc60c');

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('5b8efff798038103d269b633813fc60c', $events[0]['trace_id']);
    }

    public function testExtractsResourceAttributes(): void
    {
        $request = $this->createTraceRequestWithResourceAttrs([
            'service.name' => 'my-app',
            'service.version' => '1.0.0',
        ]);

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('my-app', $events[0]['bundle_id']);
        $this->assertSame('1.0.0', $events[0]['app_version']);
    }

    public function testMapsSpanKind(): void
    {
        $request = $this->createTraceRequest();

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('server', $events[0]['context']['span_kind']);
    }

    public function testMapsSpanStatus(): void
    {
        $request = $this->createTraceRequestWithStatus(1, 'Success');

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('ok', $events[0]['span_status']);
    }

    public function testMapsErrorStatusToSeverity(): void
    {
        $request = $this->createTraceRequestWithStatus(2, 'Connection failed');

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('error', $events[0]['span_status']);
        $this->assertSame('error', $events[0]['severity']);
        $this->assertSame('Connection failed', $events[0]['message']);
    }

    public function testMapsParentSpanId(): void
    {
        $request = $this->createTraceRequestFromJson([
            'resourceSpans' => [
                [
                    'scopeSpans' => [
                        [
                            'spans' => [
                                [
                                    'traceId' => $this->hexToBase64('5b8efff798038103d269b633813fc60c'),
                                    'spanId' => $this->hexToBase64('6364652d65373139'),
                                    'parentSpanId' => $this->hexToBase64('7061726e74737061'),
                                    'name' => 'child-operation',
                                    'startTimeUnixNano' => '1000000000000',
                                    'endTimeUnixNano' => '1000100000000',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('7061726e74737061', $events[0]['parent_span_id']);
    }

    private function createTraceRequest(): ExportTraceServiceRequest
    {
        return $this->createTraceRequestFromJson([
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => [
                            ['key' => 'service.name', 'value' => ['stringValue' => 'test-service']],
                        ],
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => ['name' => 'test-scope'],
                            'spans' => [
                                [
                                    'traceId' => $this->hexToBase64('5b8efff798038103d269b633813fc60c'),
                                    'spanId' => $this->hexToBase64('6364652d65373139'),
                                    'name' => 'test-operation',
                                    'kind' => 2,
                                    'startTimeUnixNano' => '1000000000000',
                                    'endTimeUnixNano' => '1000100000000',
                                    'status' => ['code' => 1],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function createTraceRequestWithDuration(string $startNano, string $endNano): ExportTraceServiceRequest
    {
        return $this->createTraceRequestFromJson([
            'resourceSpans' => [
                [
                    'scopeSpans' => [
                        [
                            'spans' => [
                                [
                                    'traceId' => $this->hexToBase64('5b8efff798038103d269b633813fc60c'),
                                    'spanId' => $this->hexToBase64('6364652d65373139'),
                                    'name' => 'test',
                                    'startTimeUnixNano' => $startNano,
                                    'endTimeUnixNano' => $endNano,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function createTraceRequestWithTraceId(string $traceId): ExportTraceServiceRequest
    {
        return $this->createTraceRequestFromJson([
            'resourceSpans' => [
                [
                    'scopeSpans' => [
                        [
                            'spans' => [
                                [
                                    'traceId' => $this->hexToBase64($traceId),
                                    'spanId' => $this->hexToBase64('6364652d65373139'),
                                    'name' => 'test',
                                    'startTimeUnixNano' => '1000000000000',
                                    'endTimeUnixNano' => '1000100000000',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, string> $attrs
     */
    private function createTraceRequestWithResourceAttrs(array $attrs): ExportTraceServiceRequest
    {
        $attributes = [];
        foreach ($attrs as $key => $value) {
            $attributes[] = ['key' => $key, 'value' => ['stringValue' => $value]];
        }

        return $this->createTraceRequestFromJson([
            'resourceSpans' => [
                [
                    'resource' => ['attributes' => $attributes],
                    'scopeSpans' => [
                        [
                            'spans' => [
                                [
                                    'traceId' => $this->hexToBase64('5b8efff798038103d269b633813fc60c'),
                                    'spanId' => $this->hexToBase64('6364652d65373139'),
                                    'name' => 'test',
                                    'startTimeUnixNano' => '1000000000000',
                                    'endTimeUnixNano' => '1000100000000',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function createTraceRequestWithStatus(int $code, string $message = ''): ExportTraceServiceRequest
    {
        return $this->createTraceRequestFromJson([
            'resourceSpans' => [
                [
                    'scopeSpans' => [
                        [
                            'spans' => [
                                [
                                    'traceId' => $this->hexToBase64('5b8efff798038103d269b633813fc60c'),
                                    'spanId' => $this->hexToBase64('6364652d65373139'),
                                    'name' => 'test',
                                    'startTimeUnixNano' => '1000000000000',
                                    'endTimeUnixNano' => '1000100000000',
                                    'status' => ['code' => $code, 'message' => $message],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createTraceRequestFromJson(array $data): ExportTraceServiceRequest
    {
        $request = new ExportTraceServiceRequest();
        $request->mergeFromJsonString(json_encode($data, JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * Convert hex string to base64 for protobuf JSON encoding.
     * Protobuf uses base64 encoding for bytes fields in JSON.
     */
    private function hexToBase64(string $hex): string
    {
        return base64_encode(hex2bin($hex));
    }
}
