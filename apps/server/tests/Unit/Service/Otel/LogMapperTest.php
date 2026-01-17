<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Otel;

use App\Service\Otel\LogMapper;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use PHPUnit\Framework\TestCase;

class LogMapperTest extends TestCase
{
    private LogMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new LogMapper();
    }

    public function testMapsLogToWideEventPayload(): void
    {
        $request = $this->createLogRequest();

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertCount(1, $events);
        $this->assertSame('log', $events[0]['event_type']);
        $this->assertSame('Test log message', $events[0]['message']);
    }

    public function testMapsSeverityNumber(): void
    {
        $request = $this->createLogRequestWithSeverity(17, null);

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('error', $events[0]['severity']);
    }

    public function testMapsSeverityText(): void
    {
        $request = $this->createLogRequestWithSeverity(0, 'WARNING');

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('warning', $events[0]['severity']);
    }

    public function testMapsTraceContext(): void
    {
        $request = $this->createLogRequestWithTraceContext(
            '5b8efff798038103d269b633813fc60c',
            '6364652d65373139'
        );

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('5b8efff798038103d269b633813fc60c', $events[0]['trace_id']);
        $this->assertSame('6364652d65373139', $events[0]['span_id']);
    }

    public function testExtractsExceptionAttributes(): void
    {
        $request = $this->createLogRequestFromJson([
            'resourceLogs' => [
                [
                    'scopeLogs' => [
                        [
                            'logRecords' => [
                                [
                                    'timeUnixNano' => '1000000000000',
                                    'severityNumber' => 17,
                                    'body' => ['stringValue' => 'Error occurred'],
                                    'attributes' => [
                                        ['key' => 'exception.type', 'value' => ['stringValue' => 'RuntimeException']],
                                        ['key' => 'exception.message', 'value' => ['stringValue' => 'Connection failed']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('RuntimeException', $events[0]['exception_type']);
    }

    public function testExtractsResourceAttributes(): void
    {
        $request = $this->createLogRequestFromJson([
            'resourceLogs' => [
                [
                    'resource' => [
                        'attributes' => [
                            ['key' => 'service.name', 'value' => ['stringValue' => 'my-app']],
                            ['key' => 'service.version', 'value' => ['stringValue' => '2.0.0']],
                            ['key' => 'os.type', 'value' => ['stringValue' => 'darwin']],
                        ],
                    ],
                    'scopeLogs' => [
                        [
                            'logRecords' => [
                                [
                                    'timeUnixNano' => '1000000000000',
                                    'body' => ['stringValue' => 'Test'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('my-app', $events[0]['bundle_id']);
        $this->assertSame('2.0.0', $events[0]['app_version']);
        $this->assertSame('darwin', $events[0]['os_name']);
    }

    public function testNormalizesTraceIdToLowercase(): void
    {
        // Hex strings are already lowercase after bin2hex conversion
        $request = $this->createLogRequestWithTraceContext(
            '5b8efff798038103d269b633813fc60c',
            '6364652d65373139'
        );

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('5b8efff798038103d269b633813fc60c', $events[0]['trace_id']);
        $this->assertSame('6364652d65373139', $events[0]['span_id']);
    }

    private function createLogRequest(): ExportLogsServiceRequest
    {
        return $this->createLogRequestFromJson([
            'resourceLogs' => [
                [
                    'scopeLogs' => [
                        [
                            'logRecords' => [
                                [
                                    'timeUnixNano' => '1000000000000',
                                    'severityNumber' => 9,
                                    'body' => ['stringValue' => 'Test log message'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function createLogRequestWithSeverity(int $number, ?string $text): ExportLogsServiceRequest
    {
        $logRecord = [
            'timeUnixNano' => '1000000000000',
            'severityNumber' => $number,
            'body' => ['stringValue' => 'Test'],
        ];

        if (null !== $text) {
            $logRecord['severityText'] = $text;
        }

        return $this->createLogRequestFromJson([
            'resourceLogs' => [
                [
                    'scopeLogs' => [
                        [
                            'logRecords' => [$logRecord],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function createLogRequestWithTraceContext(string $traceId, string $spanId): ExportLogsServiceRequest
    {
        return $this->createLogRequestFromJson([
            'resourceLogs' => [
                [
                    'scopeLogs' => [
                        [
                            'logRecords' => [
                                [
                                    'timeUnixNano' => '1000000000000',
                                    'body' => ['stringValue' => 'Test'],
                                    'traceId' => $this->hexToBase64($traceId),
                                    'spanId' => $this->hexToBase64($spanId),
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
    private function createLogRequestFromJson(array $data): ExportLogsServiceRequest
    {
        $request = new ExportLogsServiceRequest();
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
