<?php

declare(strict_types=1);

namespace App\Service\Otel;

use Google\Protobuf\Internal\RepeatedField;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use Opentelemetry\Proto\Metrics\V1\HistogramDataPoint;
use Opentelemetry\Proto\Metrics\V1\Metric;
use Opentelemetry\Proto\Metrics\V1\NumberDataPoint;

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
        foreach ($request->getResourceMetrics() as $resourceMetrics) {
            $resource = $resourceMetrics->getResource();
            $resourceAttrs = $resource?->getAttributes();

            foreach ($resourceMetrics->getScopeMetrics() as $scopeMetrics) {
                $scope = $scopeMetrics->getScope();
                $scopeName = $scope?->getName();

                foreach ($scopeMetrics->getMetrics() as $metric) {
                    yield from $this->mapMetricToEvents($metric, $resourceAttrs, $scopeName);
                }
            }
        }
    }

    /**
     * Map a single metric to event data.
     *
     * @param RepeatedField<\Opentelemetry\Proto\Common\V1\KeyValue>|null $resourceAttrs
     *
     * @return iterable<array<string, mixed>>
     */
    private function mapMetricToEvents(Metric $metric, ?RepeatedField $resourceAttrs, ?string $scopeName): iterable
    {
        $gauge = $metric->getGauge();
        if (null !== $gauge && $gauge->getDataPoints()->count() > 0) {
            foreach ($gauge->getDataPoints() as $dataPoint) {
                yield $this->mapNumberDataPointToEvent($metric, $dataPoint, 'gauge', $resourceAttrs, $scopeName);
            }
        }

        $sum = $metric->getSum();
        if (null !== $sum && $sum->getDataPoints()->count() > 0) {
            foreach ($sum->getDataPoints() as $dataPoint) {
                yield $this->mapNumberDataPointToEvent($metric, $dataPoint, 'sum', $resourceAttrs, $scopeName);
            }
        }

        $histogram = $metric->getHistogram();
        if (null !== $histogram && $histogram->getDataPoints()->count() > 0) {
            foreach ($histogram->getDataPoints() as $dataPoint) {
                yield $this->mapHistogramDataPointToEvent($metric, $dataPoint, $resourceAttrs, $scopeName);
            }
        }
    }

    /**
     * Map a number data point to event data.
     *
     * @param RepeatedField<\Opentelemetry\Proto\Common\V1\KeyValue>|null $resourceAttrs
     *
     * @return array<string, mixed>
     */
    private function mapNumberDataPointToEvent(
        Metric $metric,
        NumberDataPoint $dataPoint,
        string $metricType,
        ?RepeatedField $resourceAttrs,
        ?string $scopeName,
    ): array {
        $value = $this->extractNumberValue($dataPoint);
        $timestampMs = (int) ($dataPoint->getTimeUnixNano() / 1_000_000);

        $event = [
            'event_type' => 'metric',
            'metric_name' => $metric->getName(),
            'metric_value' => (float) $value,
            'metric_unit' => $metric->getUnit(),
            'timestamp' => $timestampMs,
        ];

        if (null !== $resourceAttrs) {
            $this->mapResourceAttributes($event, $resourceAttrs);
        }

        if (null !== $scopeName && '' !== $scopeName) {
            $event['context']['instrumentation_scope'] = $scopeName;
        }

        $event['context']['metric_type'] = $metricType;

        $description = $metric->getDescription();
        if ('' !== $description) {
            $event['context']['metric_description'] = $description;
        }

        $pointAttrs = ProtobufHelper::attributesToArray($dataPoint->getAttributes());
        if (!empty($pointAttrs)) {
            $event['tags'] = array_merge($event['tags'] ?? [], $pointAttrs);
        }

        return $event;
    }

    /**
     * Map a histogram data point to event data.
     *
     * @param RepeatedField<\Opentelemetry\Proto\Common\V1\KeyValue>|null $resourceAttrs
     *
     * @return array<string, mixed>
     */
    private function mapHistogramDataPointToEvent(
        Metric $metric,
        HistogramDataPoint $dataPoint,
        ?RepeatedField $resourceAttrs,
        ?string $scopeName,
    ): array {
        $count = $dataPoint->getCount();
        $sum = $dataPoint->getSum();

        $value = $sum;
        if ($count > 0 && 0.0 !== $sum) {
            $value = $sum / $count;
        }

        $timestampMs = (int) ($dataPoint->getTimeUnixNano() / 1_000_000);

        $event = [
            'event_type' => 'metric',
            'metric_name' => $metric->getName(),
            'metric_value' => (float) $value,
            'metric_unit' => $metric->getUnit(),
            'timestamp' => $timestampMs,
        ];

        if (null !== $resourceAttrs) {
            $this->mapResourceAttributes($event, $resourceAttrs);
        }

        if (null !== $scopeName && '' !== $scopeName) {
            $event['context']['instrumentation_scope'] = $scopeName;
        }

        $event['context']['metric_type'] = 'histogram';
        $event['context']['histogram'] = [
            'count' => $count,
            'sum' => $sum,
            'min' => $dataPoint->getMin(),
            'max' => $dataPoint->getMax(),
            'bucket_counts' => iterator_to_array($dataPoint->getBucketCounts()),
            'explicit_bounds' => iterator_to_array($dataPoint->getExplicitBounds()),
        ];

        $description = $metric->getDescription();
        if ('' !== $description) {
            $event['context']['metric_description'] = $description;
        }

        $pointAttrs = ProtobufHelper::attributesToArray($dataPoint->getAttributes());
        if (!empty($pointAttrs)) {
            $event['tags'] = array_merge($event['tags'] ?? [], $pointAttrs);
        }

        return $event;
    }

    /**
     * Extract numeric value from NumberDataPoint.
     */
    private function extractNumberValue(NumberDataPoint $dataPoint): float|int
    {
        // NumberDataPoint can have asDouble or asInt
        if ($dataPoint->hasAsDouble()) {
            return $dataPoint->getAsDouble();
        }

        if ($dataPoint->hasAsInt()) {
            return (int) $dataPoint->getAsInt();
        }

        return 0;
    }

    /**
     * Map resource attributes to event fields.
     *
     * @param array<string, mixed>                                   $event
     * @param RepeatedField<\Opentelemetry\Proto\Common\V1\KeyValue> $attributes
     */
    private function mapResourceAttributes(array &$event, RepeatedField $attributes): void
    {
        $serviceName = ProtobufHelper::getAttribute($attributes, 'service.name');
        if (null !== $serviceName) {
            $event['bundle_id'] = (string) $serviceName;
        }

        $serviceVersion = ProtobufHelper::getAttribute($attributes, 'service.version');
        if (null !== $serviceVersion) {
            $event['app_version'] = (string) $serviceVersion;
        }

        $osType = ProtobufHelper::getAttribute($attributes, 'os.type');
        if (null !== $osType) {
            $event['os_name'] = (string) $osType;
        }

        $osVersion = ProtobufHelper::getAttribute($attributes, 'os.version');
        if (null !== $osVersion) {
            $event['os_version'] = (string) $osVersion;
        }

        $deviceModelIdentifier = ProtobufHelper::getAttribute($attributes, 'device.model.identifier');
        if (null !== $deviceModelIdentifier) {
            $event['device_model'] = (string) $deviceModelIdentifier;
        }

        $deviceId = ProtobufHelper::getAttribute($attributes, 'device.id');
        if (null !== $deviceId) {
            $event['device_id'] = (string) $deviceId;
        }

        $envName = ProtobufHelper::getAttribute($attributes, 'deployment.environment');
        if (null !== $envName) {
            $env = (string) $envName;
            if (in_array($env, ['production', 'staging', 'development'], true)) {
                $event['environment'] = $env;
            }
        }
    }
}
