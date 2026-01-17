<?php

declare(strict_types=1);

namespace App\Service\Otel;

use Google\Protobuf\Internal\RepeatedField;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Common\V1\InstrumentationScope;
use Opentelemetry\Proto\Resource\V1\Resource;
use Opentelemetry\Proto\Trace\V1\Span;
use Opentelemetry\Proto\Trace\V1\Span\SpanKind;
use Opentelemetry\Proto\Trace\V1\Status\StatusCode;

/**
 * Maps OTLP traces to WideEventPayload format.
 */
class TraceMapper
{
    /**
     * Map an ExportTraceServiceRequest to an iterable of event data arrays.
     *
     * @return iterable<array<string, mixed>>
     */
    public function mapToEvents(ExportTraceServiceRequest $request): iterable
    {
        foreach ($request->getResourceSpans() as $resourceSpans) {
            $resource = $resourceSpans->getResource();

            foreach ($resourceSpans->getScopeSpans() as $scopeSpans) {
                $scope = $scopeSpans->getScope();

                foreach ($scopeSpans->getSpans() as $span) {
                    yield $this->mapSpanToEvent($span, $resource, $scope);
                }
            }
        }
    }

    /**
     * Map a single span to event data.
     *
     * @return array<string, mixed>
     */
    private function mapSpanToEvent(Span $span, ?Resource $resource, ?InstrumentationScope $scope): array
    {
        $traceId = ProtobufHelper::binToHex($span->getTraceId());
        $spanId = ProtobufHelper::binToHex($span->getSpanId());
        $parentSpanId = ProtobufHelper::binToHex($span->getParentSpanId());

        $startNano = $span->getStartTimeUnixNano();
        $endNano = $span->getEndTimeUnixNano();
        $durationMs = $this->calculateDurationMs($startNano, $endNano);

        $status = $span->getStatus();
        $statusCode = $status?->getCode() ?? StatusCode::STATUS_CODE_UNSET;

        $event = [
            'event_type' => 'span',
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => '' !== $parentSpanId ? $parentSpanId : null,
            'operation' => $span->getName(),
            'duration_ms' => $durationMs,
            'span_status' => $this->mapStatusCode($statusCode),
            'timestamp' => (int) ($startNano / 1_000_000),
        ];

        if (null !== $resource) {
            $this->mapResourceAttributes($event, $resource->getAttributes());
        }

        $scopeName = $scope?->getName();
        if (null !== $scopeName && '' !== $scopeName) {
            $event['context']['instrumentation_scope'] = $scopeName;
        }

        $spanAttrs = ProtobufHelper::attributesToArray($span->getAttributes());
        if (!empty($spanAttrs)) {
            $event['tags'] = array_merge($event['tags'] ?? [], $spanAttrs);
        }

        $spanEvents = $span->getEvents();
        if ($spanEvents->count() > 0) {
            $event['breadcrumbs'] = $this->mapSpanEvents($spanEvents);
        }

        $event['context']['span_kind'] = $this->mapSpanKind($span->getKind());

        $statusMessage = $status?->getMessage();
        if (null !== $statusMessage && '' !== $statusMessage) {
            $event['message'] = $statusMessage;
        }

        if (StatusCode::STATUS_CODE_ERROR === $statusCode) {
            $event['severity'] = 'error';
        }

        return $event;
    }

    /**
     * Calculate duration in milliseconds from nanosecond timestamps.
     */
    private function calculateDurationMs(int|string $startNano, int|string $endNano): float
    {
        return ((int) $endNano - (int) $startNano) / 1_000_000;
    }

    /**
     * Map status code to string.
     */
    private function mapStatusCode(int $code): string
    {
        return match ($code) {
            StatusCode::STATUS_CODE_OK => 'ok',
            StatusCode::STATUS_CODE_ERROR => 'error',
            default => 'unset',
        };
    }

    /**
     * Map span kind to string.
     */
    private function mapSpanKind(int $kind): string
    {
        return match ($kind) {
            SpanKind::SPAN_KIND_CLIENT => 'client',
            SpanKind::SPAN_KIND_SERVER => 'server',
            SpanKind::SPAN_KIND_PRODUCER => 'producer',
            SpanKind::SPAN_KIND_CONSUMER => 'consumer',
            SpanKind::SPAN_KIND_INTERNAL => 'internal',
            default => 'unspecified',
        };
    }

    /**
     * Map span events to breadcrumbs array.
     *
     * @param RepeatedField<Span\Event> $events
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapSpanEvents(RepeatedField $events): array
    {
        $breadcrumbs = [];
        foreach ($events as $event) {
            $breadcrumbs[] = [
                'name' => $event->getName(),
                'timestamp_ms' => (int) ($event->getTimeUnixNano() / 1_000_000),
                'attributes' => ProtobufHelper::attributesToArray($event->getAttributes()),
            ];
        }

        return $breadcrumbs;
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

        $allAttrs = ProtobufHelper::attributesToArray($attributes);
        $mappedKeys = [
            'service.name',
            'service.version',
            'os.type',
            'os.version',
            'device.model.identifier',
            'device.id',
            'deployment.environment',
        ];
        $remainingAttrs = array_diff_key($allAttrs, array_flip($mappedKeys));

        if (!empty($remainingAttrs)) {
            $event['context']['resource'] = $remainingAttrs;
        }
    }
}
