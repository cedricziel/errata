<?php

declare(strict_types=1);

namespace App\Service\Parquet;

use Flow\Parquet\ParquetFile\Schema;
use Flow\Parquet\ParquetFile\Schema\FlatColumn;

/**
 * Wide Event schema for Parquet storage.
 *
 * This schema handles all event types (crash, error, log, metric, span) in a unified format.
 */
class WideEventSchema
{
    public const EVENT_TYPE_CRASH = 'crash';
    public const EVENT_TYPE_ERROR = 'error';
    public const EVENT_TYPE_LOG = 'log';
    public const EVENT_TYPE_METRIC = 'metric';
    public const EVENT_TYPE_SPAN = 'span';

    public const SEVERITY_TRACE = 'trace';
    public const SEVERITY_DEBUG = 'debug';
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_FATAL = 'fatal';

    /**
     * Get the Parquet schema for wide events.
     */
    public static function getSchema(): Schema
    {
        return Schema::with(
            // Core fields (always present)
            FlatColumn::string('event_id'),
            FlatColumn::int64('timestamp'),
            FlatColumn::string('organization_id'),
            FlatColumn::string('project_id'),
            FlatColumn::string('event_type'),
            FlatColumn::string('fingerprint'),
            // Severity/Level
            FlatColumn::string('severity'),
            // Message/Content
            FlatColumn::string('message'),
            FlatColumn::string('exception_type'),
            FlatColumn::string('stack_trace'),
            // App Context
            FlatColumn::string('app_version'),
            FlatColumn::string('app_build'),
            FlatColumn::string('bundle_id'),
            FlatColumn::string('environment'),
            // Device Context
            FlatColumn::string('device_model'),
            FlatColumn::string('device_id'),
            FlatColumn::string('os_name'),
            FlatColumn::string('os_version'),
            FlatColumn::string('locale'),
            FlatColumn::string('timezone'),
            // Resource Metrics
            FlatColumn::int64('memory_used'),
            FlatColumn::int64('memory_total'),
            FlatColumn::int64('disk_free'),
            FlatColumn::float('battery_level'),
            // Span/Trace fields
            FlatColumn::string('trace_id'),
            FlatColumn::string('span_id'),
            FlatColumn::string('parent_span_id'),
            FlatColumn::string('operation'),
            FlatColumn::float('duration_ms'),
            FlatColumn::string('span_status'),
            // Metric fields
            FlatColumn::string('metric_name'),
            FlatColumn::float('metric_value'),
            FlatColumn::string('metric_unit'),
            // User Context
            FlatColumn::string('user_id'),
            FlatColumn::string('session_id'),
            // Extensible dimensions (JSON strings)
            FlatColumn::string('tags'),
            FlatColumn::string('context'),
            FlatColumn::string('breadcrumbs'),
        );
    }

    /**
     * Get the list of required fields that must always be present.
     *
     * @return array<string>
     */
    public static function getRequiredFields(): array
    {
        return [
            'event_id',
            'timestamp',
            'project_id',
            'event_type',
        ];
    }

    /**
     * Get all available event types.
     *
     * @return array<string>
     */
    public static function getEventTypes(): array
    {
        return [
            self::EVENT_TYPE_CRASH,
            self::EVENT_TYPE_ERROR,
            self::EVENT_TYPE_LOG,
            self::EVENT_TYPE_METRIC,
            self::EVENT_TYPE_SPAN,
        ];
    }

    /**
     * Get all available severity levels.
     *
     * @return array<string>
     */
    public static function getSeverityLevels(): array
    {
        return [
            self::SEVERITY_TRACE,
            self::SEVERITY_DEBUG,
            self::SEVERITY_INFO,
            self::SEVERITY_WARNING,
            self::SEVERITY_ERROR,
            self::SEVERITY_FATAL,
        ];
    }

    /**
     * Validate event data against the schema requirements.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string> List of validation errors
     */
    public static function validate(array $data): array
    {
        $errors = [];

        foreach (self::getRequiredFields() as $field) {
            if (!isset($data[$field]) || '' === $data[$field]) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        if (isset($data['event_type']) && !in_array($data['event_type'], self::getEventTypes(), true)) {
            $errors[] = "Invalid event_type: {$data['event_type']}";
        }

        if (isset($data['severity']) && !in_array($data['severity'], self::getSeverityLevels(), true)) {
            $errors[] = "Invalid severity: {$data['severity']}";
        }

        return $errors;
    }

    /**
     * Normalize event data to ensure all fields exist with proper defaults.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        return array_merge([
            // Core fields
            'event_id' => null,
            'timestamp' => null,
            'organization_id' => null,
            'project_id' => null,
            'event_type' => null,
            'fingerprint' => null,

            // Severity
            'severity' => null,

            // Message/Content
            'message' => null,
            'exception_type' => null,
            'stack_trace' => null,

            // App Context
            'app_version' => null,
            'app_build' => null,
            'bundle_id' => null,
            'environment' => null,

            // Device Context
            'device_model' => null,
            'device_id' => null,
            'os_name' => null,
            'os_version' => null,
            'locale' => null,
            'timezone' => null,

            // Resource Metrics
            'memory_used' => null,
            'memory_total' => null,
            'disk_free' => null,
            'battery_level' => null,

            // Span/Trace
            'trace_id' => null,
            'span_id' => null,
            'parent_span_id' => null,
            'operation' => null,
            'duration_ms' => null,
            'span_status' => null,

            // Metric
            'metric_name' => null,
            'metric_value' => null,
            'metric_unit' => null,

            // User Context
            'user_id' => null,
            'session_id' => null,

            // Extensible
            'tags' => null,
            'context' => null,
            'breadcrumbs' => null,
        ], $data);
    }
}
