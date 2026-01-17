<?php

declare(strict_types=1);

namespace App\Service\Otel;

use App\DTO\Otel\Common\Resource;
use App\DTO\Otel\Logs\ExportLogsServiceRequest;
use App\DTO\Otel\Logs\LogRecord;

/**
 * Maps OTLP logs to WideEventPayload format.
 */
class LogMapper
{
    /**
     * Map an ExportLogsServiceRequest to an iterable of event data arrays.
     *
     * @return iterable<array<string, mixed>>
     */
    public function mapToEvents(ExportLogsServiceRequest $request): iterable
    {
        foreach ($request->resourceLogs as $resourceLogs) {
            $resource = $resourceLogs->resource;

            foreach ($resourceLogs->scopeLogs as $scopeLogs) {
                $scopeName = $scopeLogs->scope?->name;

                foreach ($scopeLogs->logRecords as $logRecord) {
                    yield $this->mapLogToEvent($logRecord, $resource, $scopeName);
                }
            }
        }
    }

    /**
     * Map a single log record to event data.
     *
     * @return array<string, mixed>
     */
    private function mapLogToEvent(LogRecord $log, ?Resource $resource, ?string $scopeName): array
    {
        $event = [
            'event_type' => 'log',
            'message' => $log->getMessage(),
            'severity' => $log->getSeverityString(),
            'timestamp' => (int) $log->getTimestampMs(),
        ];

        if (null !== $log->traceId) {
            $event['trace_id'] = $log->traceId;
        }

        if (null !== $log->spanId) {
            $event['span_id'] = $log->spanId;
        }

        if (null !== $resource) {
            $this->mapResourceAttributes($event, $resource);
        }

        if (null !== $scopeName && '' !== $scopeName) {
            $event['context']['instrumentation_scope'] = $scopeName;
        }

        $logAttrs = $log->getAttributesAsArray();
        if (!empty($logAttrs)) {
            $this->extractKnownAttributes($event, $logAttrs);

            if (!empty($logAttrs)) {
                $event['tags'] = array_merge($event['tags'] ?? [], $logAttrs);
            }
        }

        return $event;
    }

    /**
     * Extract known attributes from log attributes.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $attrs
     */
    private function extractKnownAttributes(array &$event, array &$attrs): void
    {
        if (isset($attrs['exception.type'])) {
            $event['exception_type'] = (string) $attrs['exception.type'];
            unset($attrs['exception.type']);
        }

        if (isset($attrs['exception.message'])) {
            if (null === $event['message'] || '' === $event['message']) {
                $event['message'] = (string) $attrs['exception.message'];
            }
            unset($attrs['exception.message']);
        }

        if (isset($attrs['exception.stacktrace'])) {
            $stacktrace = $attrs['exception.stacktrace'];
            if (is_string($stacktrace)) {
                $event['stack_trace'] = array_map(
                    static fn (string $line) => ['raw' => $line],
                    explode("\n", $stacktrace)
                );
            }
            unset($attrs['exception.stacktrace']);
        }

        if (isset($attrs['enduser.id'])) {
            $event['user_id'] = (string) $attrs['enduser.id'];
            unset($attrs['enduser.id']);
        }

        if (isset($attrs['session.id'])) {
            $event['session_id'] = (string) $attrs['session.id'];
            unset($attrs['session.id']);
        }
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
