<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Otel;

use App\Service\Otel\MetricMapper;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use PHPUnit\Framework\TestCase;

class MetricMapperTest extends TestCase
{
    private MetricMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new MetricMapper();
    }

    public function testMapsGaugeMetric(): void
    {
        $request = $this->createGaugeMetricRequest();

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertCount(1, $events);
        $this->assertSame('metric', $events[0]['event_type']);
        $this->assertSame('cpu.usage', $events[0]['metric_name']);
        $this->assertSame(75.5, $events[0]['metric_value']);
        $this->assertSame('%', $events[0]['metric_unit']);
    }

    public function testMapsSumMetric(): void
    {
        $request = $this->createSumMetricRequest();

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('sum', $events[0]['context']['metric_type']);
        $this->assertSame('http.requests.total', $events[0]['metric_name']);
        $this->assertSame(1000.0, $events[0]['metric_value']);
    }

    public function testMapsHistogramMetric(): void
    {
        $request = $this->createHistogramMetricRequest();

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('histogram', $events[0]['context']['metric_type']);
        $this->assertSame('http.request.duration', $events[0]['metric_name']);
        $this->assertArrayHasKey('histogram', $events[0]['context']);
        $this->assertSame(100, $events[0]['context']['histogram']['count']);
    }

    public function testExtractsResourceAttributes(): void
    {
        $request = $this->createMetricRequestFromJson([
            'resourceMetrics' => [
                [
                    'resource' => [
                        'attributes' => [
                            ['key' => 'service.name', 'value' => ['stringValue' => 'metrics-app']],
                            ['key' => 'service.version', 'value' => ['stringValue' => '3.0.0']],
                        ],
                    ],
                    'scopeMetrics' => [
                        [
                            'metrics' => [
                                [
                                    'name' => 'test.metric',
                                    'gauge' => [
                                        'dataPoints' => [
                                            ['timeUnixNano' => '1000000000000', 'asDouble' => 1.0],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('metrics-app', $events[0]['bundle_id']);
        $this->assertSame('3.0.0', $events[0]['app_version']);
    }

    public function testMapsDataPointAttributes(): void
    {
        $request = $this->createMetricRequestFromJson([
            'resourceMetrics' => [
                [
                    'scopeMetrics' => [
                        [
                            'metrics' => [
                                [
                                    'name' => 'test.metric',
                                    'gauge' => [
                                        'dataPoints' => [
                                            [
                                                'timeUnixNano' => '1000000000000',
                                                'asDouble' => 1.0,
                                                'attributes' => [
                                                    ['key' => 'host', 'value' => ['stringValue' => 'server-1']],
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
        ]);

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame('server-1', $events[0]['tags']['host']);
    }

    public function testMapsIntValue(): void
    {
        $request = $this->createMetricRequestFromJson([
            'resourceMetrics' => [
                [
                    'scopeMetrics' => [
                        [
                            'metrics' => [
                                [
                                    'name' => 'connections.active',
                                    'gauge' => [
                                        'dataPoints' => [
                                            ['timeUnixNano' => '1000000000000', 'asInt' => '42'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $events = iterator_to_array($this->mapper->mapToEvents($request));

        $this->assertSame(42.0, $events[0]['metric_value']);
    }

    private function createGaugeMetricRequest(): ExportMetricsServiceRequest
    {
        return $this->createMetricRequestFromJson([
            'resourceMetrics' => [
                [
                    'scopeMetrics' => [
                        [
                            'metrics' => [
                                [
                                    'name' => 'cpu.usage',
                                    'unit' => '%',
                                    'gauge' => [
                                        'dataPoints' => [
                                            ['timeUnixNano' => '1000000000000', 'asDouble' => 75.5],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function createSumMetricRequest(): ExportMetricsServiceRequest
    {
        return $this->createMetricRequestFromJson([
            'resourceMetrics' => [
                [
                    'scopeMetrics' => [
                        [
                            'metrics' => [
                                [
                                    'name' => 'http.requests.total',
                                    'sum' => [
                                        'aggregationTemporality' => 2,
                                        'isMonotonic' => true,
                                        'dataPoints' => [
                                            ['timeUnixNano' => '1000000000000', 'asInt' => '1000'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function createHistogramMetricRequest(): ExportMetricsServiceRequest
    {
        return $this->createMetricRequestFromJson([
            'resourceMetrics' => [
                [
                    'scopeMetrics' => [
                        [
                            'metrics' => [
                                [
                                    'name' => 'http.request.duration',
                                    'unit' => 'ms',
                                    'histogram' => [
                                        'aggregationTemporality' => 2,
                                        'dataPoints' => [
                                            [
                                                'timeUnixNano' => '1000000000000',
                                                'count' => '100',
                                                'sum' => 5000.0,
                                                'min' => 10.0,
                                                'max' => 200.0,
                                                'bucketCounts' => ['10', '30', '40', '15', '5'],
                                                'explicitBounds' => [25.0, 50.0, 100.0, 150.0],
                                            ],
                                        ],
                                    ],
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
    private function createMetricRequestFromJson(array $data): ExportMetricsServiceRequest
    {
        $request = new ExportMetricsServiceRequest();
        $request->mergeFromJsonString(json_encode($data, JSON_THROW_ON_ERROR));

        return $request;
    }
}
