<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Otel;

use App\DTO\Otel\Logs\ExportLogsServiceRequest;
use App\Service\Otel\LogMapper;
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
        $request = ExportLogsServiceRequest::fromArray([
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
        $request = ExportLogsServiceRequest::fromArray([
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
        $request = $this->createLogRequestWithTraceContext(
            '5B8EFFF798038103D269B633813FC60C',
            '6364652D65373139'
        );

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('5b8efff798038103d269b633813fc60c', $events[0]['trace_id']);
        $this->assertSame('6364652d65373139', $events[0]['span_id']);
    }

    private function createLogRequest(): ExportLogsServiceRequest
    {
        return ExportLogsServiceRequest::fromArray([
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

        return ExportLogsServiceRequest::fromArray([
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
        return ExportLogsServiceRequest::fromArray([
            'resourceLogs' => [
                [
                    'scopeLogs' => [
                        [
                            'logRecords' => [
                                [
                                    'timeUnixNano' => '1000000000000',
                                    'body' => ['stringValue' => 'Test'],
                                    'traceId' => $traceId,
                                    'spanId' => $spanId,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
