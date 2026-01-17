<?php

declare(strict_types=1);

namespace App\Service\Otel;

use Google\Protobuf\Internal\RepeatedField;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use Opentelemetry\Proto\Logs\V1\LogRecord;
use Opentelemetry\Proto\Logs\V1\SeverityNumber;

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
        foreach ($request->getResourceLogs() as $resourceLogs) {
            $resource = $resourceLogs->getResource();
            $resourceAttrs = $resource?->getAttributes();

            foreach ($resourceLogs->getScopeLogs() as $scopeLogs) {
                $scope = $scopeLogs->getScope();
                $scopeName = $scope?->getName();

                foreach ($scopeLogs->getLogRecords() as $logRecord) {
                    yield $this->mapLogToEvent($logRecord, $resourceAttrs, $scopeName);
                }
            }
        }
    }

    /**
     * Map a single log record to event data.
     *
     * @param RepeatedField<\Opentelemetry\Proto\Common\V1\KeyValue>|null $resourceAttrs
     *
     * @return array<string, mixed>
     */
    private function mapLogToEvent(LogRecord $log, ?RepeatedField $resourceAttrs, ?string $scopeName): array
    {
        $timestampMs = (int) ($log->getTimeUnixNano() / 1_000_000);
        $message = $this->extractMessage($log);
        $severity = $this->mapSeverity($log);

        $event = [
            'event_type' => 'log',
            'message' => $message,
            'severity' => $severity,
            'timestamp' => $timestampMs,
        ];

        $traceId = ProtobufHelper::binToHex($log->getTraceId());
        if ('' !== $traceId) {
            $event['trace_id'] = $traceId;
        }

        $spanId = ProtobufHelper::binToHex($log->getSpanId());
        if ('' !== $spanId) {
            $event['span_id'] = $spanId;
        }

        if (null !== $resourceAttrs) {
            $this->mapResourceAttributes($event, $resourceAttrs);
        }

        if (null !== $scopeName && '' !== $scopeName) {
            $event['context']['instrumentation_scope'] = $scopeName;
        }

        $logAttrs = ProtobufHelper::attributesToArray($log->getAttributes());
        if (!empty($logAttrs)) {
            $this->extractKnownAttributes($event, $logAttrs);

            if (!empty($logAttrs)) {
                $event['tags'] = array_merge($event['tags'] ?? [], $logAttrs);
            }
        }

        return $event;
    }

    /**
     * Extract message from log body.
     */
    private function extractMessage(LogRecord $log): ?string
    {
        $body = $log->getBody();
        if (null === $body) {
            return null;
        }

        $value = ProtobufHelper::extractValue($body);

        return is_string($value) ? $value : null;
    }

    /**
     * Map severity from log record.
     */
    private function mapSeverity(LogRecord $log): string
    {
        // First check severity text
        $severityText = $log->getSeverityText();
        if ('' !== $severityText) {
            return strtolower($severityText);
        }

        // Fall back to severity number
        $severityNumber = $log->getSeverityNumber();

        return $this->mapSeverityNumber($severityNumber);
    }

    /**
     * Map severity number to string.
     */
    private function mapSeverityNumber(int $severityNumber): string
    {
        return match (true) {
            $severityNumber >= SeverityNumber::SEVERITY_NUMBER_FATAL => 'fatal',
            $severityNumber >= SeverityNumber::SEVERITY_NUMBER_ERROR => 'error',
            $severityNumber >= SeverityNumber::SEVERITY_NUMBER_WARN => 'warning',
            $severityNumber >= SeverityNumber::SEVERITY_NUMBER_INFO => 'info',
            $severityNumber >= SeverityNumber::SEVERITY_NUMBER_DEBUG => 'debug',
            $severityNumber >= SeverityNumber::SEVERITY_NUMBER_TRACE => 'trace',
            default => 'unknown',
        };
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
