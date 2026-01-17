<?php

declare(strict_types=1);

namespace App\Service\Otel;

use App\DTO\Otel\Common\Resource;
use App\DTO\Otel\Trace\ExportTraceServiceRequest;
use App\DTO\Otel\Trace\Span;

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
        foreach ($request->resourceSpans as $resourceSpans) {
            $resource = $resourceSpans->resource;

            foreach ($resourceSpans->scopeSpans as $scopeSpans) {
                $scope = $scopeSpans->scope;

                foreach ($scopeSpans->spans as $span) {
                    yield $this->mapSpanToEvent($span, $resource, $scope?->name);
                }
            }
        }
    }

    /**
     * Map a single span to event data.
     *
     * @return array<string, mixed>
     */
    private function mapSpanToEvent(Span $span, ?Resource $resource, ?string $scopeName): array
    {
        $event = [
            'event_type' => 'span',
            'trace_id' => $span->traceId,
            'span_id' => $span->spanId,
            'parent_span_id' => $span->parentSpanId,
            'operation' => $span->name,
            'duration_ms' => $span->getDurationMs(),
            'span_status' => $span->status?->getStatusString() ?? 'unset',
            'timestamp' => (int) $span->getStartTimestampMs(),
        ];

        if (null !== $resource) {
            $this->mapResourceAttributes($event, $resource);
        }

        if (null !== $scopeName && '' !== $scopeName) {
            $event['context']['instrumentation_scope'] = $scopeName;
        }

        $spanAttrs = $span->getAttributesAsArray();
        if (!empty($spanAttrs)) {
            $event['tags'] = array_merge($event['tags'] ?? [], $spanAttrs);
        }

        if (!empty($span->events)) {
            $event['breadcrumbs'] = $span->getEventsAsArray();
        }

        $event['context']['span_kind'] = $span->getKindString();

        if ($span->status?->message) {
            $event['message'] = $span->status->message;
        }

        if (2 === $span->status?->code) {
            $event['severity'] = 'error';
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

        $allAttrs = $resource->getAttributesAsArray();
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
