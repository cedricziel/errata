<?php

declare(strict_types=1);

namespace App\Service\Otel;

use App\DTO\Otel\Common\Resource;
use App\DTO\Otel\Metrics\ExportMetricsServiceRequest;
use App\DTO\Otel\Metrics\HistogramDataPoint;
use App\DTO\Otel\Metrics\Metric;
use App\DTO\Otel\Metrics\NumberDataPoint;

/**
 * Maps OTLP metrics to WideEventPayload format.
 */
class MetricMapper
{
    /**
     * Map an ExportMetricsServiceRequest to an iterable of event data arrays.
     *
     * @return iterable<array<string, mixed>>
     */
    public function mapToEvents(ExportMetricsServiceRequest $request): iterable
    {
        foreach ($request->resourceMetrics as $resourceMetrics) {
            $resource = $resourceMetrics->resource;

            foreach ($resourceMetrics->scopeMetrics as $scopeMetrics) {
                $scopeName = $scopeMetrics->scope?->name;

                foreach ($scopeMetrics->metrics as $metric) {
                    yield from $this->mapMetricToEvents($metric, $resource, $scopeName);
                }
            }
        }
    }

    /**
     * Map a single metric to event data.
     *
     * @return iterable<array<string, mixed>>
     */
    private function mapMetricToEvents(Metric $metric, ?Resource $resource, ?string $scopeName): iterable
    {
        if (null !== $metric->gauge) {
            foreach ($metric->gauge->dataPoints as $dataPoint) {
                yield $this->mapDataPointToEvent($metric, $dataPoint, 'gauge', $resource, $scopeName);
            }
        }

        if (null !== $metric->sum) {
            foreach ($metric->sum->dataPoints as $dataPoint) {
                yield $this->mapDataPointToEvent($metric, $dataPoint, 'sum', $resource, $scopeName);
            }
        }

        if (null !== $metric->histogram) {
            foreach ($metric->histogram->dataPoints as $dataPoint) {
                yield $this->mapHistogramDataPointToEvent($metric, $dataPoint, $resource, $scopeName);
            }
        }
    }

    /**
     * Map a number data point to event data.
     *
     * @return array<string, mixed>
     */
    private function mapDataPointToEvent(
        Metric $metric,
        NumberDataPoint $dataPoint,
        string $metricType,
        ?Resource $resource,
        ?string $scopeName,
    ): array {
        $event = [
            'event_type' => 'metric',
            'metric_name' => $metric->name,
            'metric_value' => (float) ($dataPoint->getValue() ?? 0),
            'metric_unit' => $metric->unit,
            'timestamp' => (int) $dataPoint->getTimestampMs(),
        ];

        if (null !== $resource) {
            $this->mapResourceAttributes($event, $resource);
        }

        if (null !== $scopeName && '' !== $scopeName) {
            $event['context']['instrumentation_scope'] = $scopeName;
        }

        $event['context']['metric_type'] = $metricType;

        if ('' !== $metric->description) {
            $event['context']['metric_description'] = $metric->description;
        }

        $pointAttrs = $dataPoint->getAttributesAsArray();
        if (!empty($pointAttrs)) {
            $event['tags'] = array_merge($event['tags'] ?? [], $pointAttrs);
        }

        return $event;
    }

    /**
     * Map a histogram data point to event data.
     *
     * @return array<string, mixed>
     */
    private function mapHistogramDataPointToEvent(
        Metric $metric,
        HistogramDataPoint $dataPoint,
        ?Resource $resource,
        ?string $scopeName,
    ): array {
        $value = $dataPoint->sum ?? 0;
        if ($dataPoint->count > 0 && null !== $dataPoint->sum) {
            $value = $dataPoint->sum / $dataPoint->count;
        }

        $event = [
            'event_type' => 'metric',
            'metric_name' => $metric->name,
            'metric_value' => (float) $value,
            'metric_unit' => $metric->unit,
            'timestamp' => (int) $dataPoint->getTimestampMs(),
        ];

        if (null !== $resource) {
            $this->mapResourceAttributes($event, $resource);
        }

        if (null !== $scopeName && '' !== $scopeName) {
            $event['context']['instrumentation_scope'] = $scopeName;
        }

        $event['context']['metric_type'] = 'histogram';
        $event['context']['histogram'] = [
            'count' => $dataPoint->count,
            'sum' => $dataPoint->sum,
            'min' => $dataPoint->min,
            'max' => $dataPoint->max,
            'bucket_counts' => $dataPoint->bucketCounts,
            'explicit_bounds' => $dataPoint->explicitBounds,
        ];

        if ('' !== $metric->description) {
            $event['context']['metric_description'] = $metric->description;
        }

        $pointAttrs = $dataPoint->getAttributesAsArray();
        if (!empty($pointAttrs)) {
            $event['tags'] = array_merge($event['tags'] ?? [], $pointAttrs);
        }

        return $event;
    }

    /**
     * Map resource attributes to event fields.
     *
     * @param array<string, mixed> $event
     */
    private function mapResourceAttributes(array &$event, Resource $resource): void
    {
        $serviceName = $resource->getAttribute('service.name');
        if (null !== $serviceName) {
            $event['bundle_id'] = (string) $serviceName;
        }

        $serviceVersion = $resource->getAttribute('service.version');
        if (null !== $serviceVersion) {
            $event['app_version'] = (string) $serviceVersion;
        }

        $osType = $resource->getAttribute('os.type');
        if (null !== $osType) {
            $event['os_name'] = (string) $osType;
        }

        $osVersion = $resource->getAttribute('os.version');
        if (null !== $osVersion) {
            $event['os_version'] = (string) $osVersion;
        }

        $deviceModelIdentifier = $resource->getAttribute('device.model.identifier');
        if (null !== $deviceModelIdentifier) {
            $event['device_model'] = (string) $deviceModelIdentifier;
        }

        $deviceId = $resource->getAttribute('device.id');
        if (null !== $deviceId) {
            $event['device_id'] = (string) $deviceId;
        }

        $envName = $resource->getAttribute('deployment.environment');
        if (null !== $envName) {
            $env = (string) $envName;
            if (in_array($env, ['production', 'staging', 'development'], true)) {
                $event['environment'] = $env;
            }
        }
    }
}
