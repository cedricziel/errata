<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Service for generating fingerprints to group related events into issues.
 *
 * The fingerprint is a SHA-256 hash that uniquely identifies a class of similar events.
 */
class FingerprintService
{
    /**
     * Generate a fingerprint for an event.
     *
     * @param array<string, mixed> $event
     */
    public function generateFingerprint(array $event): string
    {
        $eventType = $event['event_type'] ?? 'unknown';

        $components = match ($eventType) {
            'crash', 'error' => $this->getErrorFingerprintComponents($event),
            'log' => $this->getLogFingerprintComponents($event),
            'metric' => $this->getMetricFingerprintComponents($event),
            'span' => $this->getSpanFingerprintComponents($event),
            default => $this->getDefaultFingerprintComponents($event),
        };

        return hash('sha256', implode('|', $components));
    }

    /**
     * Get fingerprint components for crash/error events.
     *
     * @param array<string, mixed> $event
     *
     * @return array<string>
     */
    private function getErrorFingerprintComponents(array $event): array
    {
        $components = [
            $event['event_type'] ?? '',
            $event['exception_type'] ?? '',
        ];

        // Add the top stack frame if available
        if (!empty($event['stack_trace'])) {
            $stackTrace = $event['stack_trace'];

            // If it's a JSON string, decode it
            if (is_string($stackTrace)) {
                $stackTrace = json_decode($stackTrace, true) ?? [];
            }

            if (is_array($stackTrace) && !empty($stackTrace)) {
                $topFrame = $stackTrace[0] ?? [];
                $components[] = $this->normalizeStackFrame($topFrame);
            }
        }

        // If no stack trace, use message (with numbers removed)
        if (empty($event['stack_trace']) || (count($components) <= 2)) {
            $message = $event['message'] ?? '';
            $components[] = $this->normalizeMessage($message);
        }

        return array_filter($components, fn ($c) => '' !== $c);
    }

    /**
     * Get fingerprint components for log events.
     *
     * @param array<string, mixed> $event
     *
     * @return array<string>
     */
    private function getLogFingerprintComponents(array $event): array
    {
        return [
            'log',
            $event['severity'] ?? '',
            $this->normalizeMessage($event['message'] ?? ''),
        ];
    }

    /**
     * Get fingerprint components for metric events.
     *
     * @param array<string, mixed> $event
     *
     * @return array<string>
     */
    private function getMetricFingerprintComponents(array $event): array
    {
        return [
            'metric',
            $event['metric_name'] ?? '',
            $event['metric_unit'] ?? '',
        ];
    }

    /**
     * Get fingerprint components for span events.
     *
     * @param array<string, mixed> $event
     *
     * @return array<string>
     */
    private function getSpanFingerprintComponents(array $event): array
    {
        return [
            'span',
            $event['operation'] ?? '',
            $event['span_status'] ?? '',
        ];
    }

    /**
     * Get default fingerprint components.
     *
     * @param array<string, mixed> $event
     *
     * @return array<string>
     */
    private function getDefaultFingerprintComponents(array $event): array
    {
        return [
            $event['event_type'] ?? 'unknown',
            $this->normalizeMessage($event['message'] ?? ''),
        ];
    }

    /**
     * Normalize a stack frame to a string for fingerprinting.
     *
     * @param array<string, mixed> $frame
     */
    private function normalizeStackFrame(array $frame): string
    {
        $parts = [];

        // Module/file
        if (!empty($frame['module'])) {
            $parts[] = $frame['module'];
        } elseif (!empty($frame['filename'])) {
            // Strip path, keep just filename
            $parts[] = basename($frame['filename']);
        }

        // Function/method
        if (!empty($frame['function'])) {
            $parts[] = $frame['function'];
        }

        // Line number (optional, helps distinguish similar crashes)
        if (!empty($frame['lineno'])) {
            $parts[] = 'L'.$frame['lineno'];
        }

        return implode(':', $parts);
    }

    /**
     * Normalize a message for fingerprinting.
     *
     * Removes variable content like numbers, UUIDs, timestamps, etc.
     */
    private function normalizeMessage(string $message): string
    {
        // Truncate long messages
        if (strlen($message) > 500) {
            $message = substr($message, 0, 500);
        }

        // Replace UUIDs
        $message = preg_replace(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '<UUID>',
            $message
        );

        // Replace hex strings (memory addresses, hashes)
        $message = preg_replace('/0x[0-9a-f]+/i', '<HEX>', $message);
        $message = preg_replace('/\b[0-9a-f]{32,}\b/i', '<HASH>', $message);

        // Replace numbers (but preserve single digits that might be part of names)
        $message = preg_replace('/\b\d{2,}\b/', '<N>', $message);

        // Replace IP addresses
        $message = preg_replace('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', '<IP>', $message);

        // Replace URLs
        $message = preg_replace('#https?://[^\s<>"{}|\\^`\[\]]+#', '<URL>', $message);

        // Replace email addresses
        $message = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '<EMAIL>', $message);

        // Normalize whitespace
        $message = preg_replace('/\s+/', ' ', trim($message));

        return $message;
    }

    /**
     * Extract a short title from an event for the issue.
     *
     * @param array<string, mixed> $event
     */
    public function extractTitle(array $event): string
    {
        $eventType = $event['event_type'] ?? 'unknown';

        $title = match ($eventType) {
            'crash', 'error' => $this->extractErrorTitle($event),
            'log' => $this->extractLogTitle($event),
            'metric' => $this->extractMetricTitle($event),
            'span' => $this->extractSpanTitle($event),
            default => $event['message'] ?? 'Unknown event',
        };

        // Truncate to reasonable length
        if (strlen($title) > 200) {
            $title = substr($title, 0, 197).'...';
        }

        return $title;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function extractErrorTitle(array $event): string
    {
        $parts = [];

        if (!empty($event['exception_type'])) {
            $parts[] = $event['exception_type'];
        }

        if (!empty($event['message'])) {
            $message = $event['message'];
            // Take first line only
            $firstLine = strtok($message, "\n");
            $parts[] = $firstLine;
        }

        return implode(': ', $parts) ?: 'Unknown error';
    }

    /**
     * @param array<string, mixed> $event
     */
    private function extractLogTitle(array $event): string
    {
        $severity = strtoupper($event['severity'] ?? 'LOG');
        $message = $event['message'] ?? 'Log message';

        // Take first line
        $firstLine = strtok($message, "\n");

        return "[{$severity}] ".($firstLine ?: $message);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function extractMetricTitle(array $event): string
    {
        $name = $event['metric_name'] ?? 'metric';
        $unit = $event['metric_unit'] ?? '';

        return "Metric: {$name}".($unit ? " ({$unit})" : '');
    }

    /**
     * @param array<string, mixed> $event
     */
    private function extractSpanTitle(array $event): string
    {
        $operation = $event['operation'] ?? 'operation';
        $status = $event['span_status'] ?? '';

        return "Span: {$operation}".($status ? " [{$status}]" : '');
    }

    /**
     * Extract culprit (source location) from an event.
     *
     * @param array<string, mixed> $event
     */
    public function extractCulprit(array $event): ?string
    {
        if (empty($event['stack_trace'])) {
            return null;
        }

        $stackTrace = $event['stack_trace'];
        if (is_string($stackTrace)) {
            $stackTrace = json_decode($stackTrace, true) ?? [];
        }

        if (!is_array($stackTrace) || empty($stackTrace)) {
            return null;
        }

        // Find the first frame from user code (not system/framework)
        foreach ($stackTrace as $frame) {
            if ($this->isUserCodeFrame($frame)) {
                return $this->formatCulprit($frame);
            }
        }

        // Fall back to first frame
        return $this->formatCulprit($stackTrace[0] ?? []);
    }

    /**
     * Check if a stack frame is from user code.
     *
     * @param array<string, mixed> $frame
     */
    private function isUserCodeFrame(array $frame): bool
    {
        $module = $frame['module'] ?? $frame['filename'] ?? '';

        // Skip system frameworks
        $systemPrefixes = [
            'Foundation',
            'UIKit',
            'CoreFoundation',
            'libsystem',
            'libobjc',
            'libdispatch',
            'CFNetwork',
        ];

        foreach ($systemPrefixes as $prefix) {
            if (str_starts_with($module, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Format a stack frame as a culprit string.
     *
     * @param array<string, mixed> $frame
     */
    private function formatCulprit(array $frame): string
    {
        $parts = [];

        if (!empty($frame['module'])) {
            $parts[] = $frame['module'];
        } elseif (!empty($frame['filename'])) {
            $parts[] = basename($frame['filename']);
        }

        if (!empty($frame['function'])) {
            $parts[] = $frame['function'];
        }

        if (!empty($frame['lineno'])) {
            return implode(' in ', $parts).':'.$frame['lineno'];
        }

        return implode(' in ', $parts);
    }
}
