<?php

declare(strict_types=1);

namespace App\DTO\Otel\Logs;

use App\DTO\Otel\Common\AnyValue;
use App\DTO\Otel\Common\KeyValue;

/**
 * Represents an OTLP LogRecord.
 */
class LogRecord
{
    public const SEVERITY_UNSPECIFIED = 0;
    public const SEVERITY_TRACE = 1;
    public const SEVERITY_DEBUG = 5;
    public const SEVERITY_INFO = 9;
    public const SEVERITY_WARN = 13;
    public const SEVERITY_ERROR = 17;
    public const SEVERITY_FATAL = 21;

    /** @var string Unix nanoseconds since epoch as string */
    public string $timeUnixNano = '0';

    /** @var string Unix nanoseconds when observed */
    public string $observedTimeUnixNano = '0';

    public int $severityNumber = self::SEVERITY_UNSPECIFIED;

    public ?string $severityText = null;

    public ?AnyValue $body = null;

    /** @var list<KeyValue> */
    public array $attributes = [];

    public int $droppedAttributesCount = 0;

    public int $flags = 0;

    public ?string $traceId = null;

    public ?string $spanId = null;

    /**
     * Get timestamp as milliseconds since epoch.
     */
    public function getTimestampMs(): float
    {
        $nano = $this->timeUnixNano ?: $this->observedTimeUnixNano;

        return (float) $nano / 1_000_000;
    }

    /**
     * Map severity number to a standard severity string.
     */
    public function getSeverityString(): string
    {
        if (null !== $this->severityText && '' !== $this->severityText) {
            $text = strtolower($this->severityText);
            $validLevels = ['trace', 'debug', 'info', 'warning', 'error', 'fatal'];
            if (in_array($text, $validLevels, true)) {
                return $text;
            }
            if ('warn' === $text) {
                return 'warning';
            }
        }

        return match (true) {
            $this->severityNumber >= self::SEVERITY_FATAL => 'fatal',
            $this->severityNumber >= self::SEVERITY_ERROR => 'error',
            $this->severityNumber >= self::SEVERITY_WARN => 'warning',
            $this->severityNumber >= self::SEVERITY_INFO => 'info',
            $this->severityNumber >= self::SEVERITY_DEBUG => 'debug',
            $this->severityNumber >= self::SEVERITY_TRACE => 'trace',
            default => 'info',
        };
    }

    /**
     * Get the message from the body.
     */
    public function getMessage(): ?string
    {
        if (null === $this->body) {
            return null;
        }

        $value = $this->body->getValue();
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return json_encode($value) ?: null;
        }

        return null;
    }

    /**
     * Get attributes as an associative array.
     *
     * @return array<string, mixed>
     */
    public function getAttributesAsArray(): array
    {
        $result = [];
        foreach ($this->attributes as $attr) {
            $result[$attr->key] = $attr->getValue();
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $log = new self();
        $log->timeUnixNano = (string) ($data['timeUnixNano'] ?? '0');
        $log->observedTimeUnixNano = (string) ($data['observedTimeUnixNano'] ?? '0');
        $log->severityNumber = (int) ($data['severityNumber'] ?? self::SEVERITY_UNSPECIFIED);
        $log->severityText = isset($data['severityText']) ? (string) $data['severityText'] : null;
        $log->droppedAttributesCount = (int) ($data['droppedAttributesCount'] ?? 0);
        $log->flags = (int) ($data['flags'] ?? 0);
        $log->traceId = isset($data['traceId']) && '' !== $data['traceId']
            ? strtolower((string) $data['traceId'])
            : null;
        $log->spanId = isset($data['spanId']) && '' !== $data['spanId']
            ? strtolower((string) $data['spanId'])
            : null;

        if (isset($data['body']) && is_array($data['body'])) {
            $log->body = AnyValue::fromArray($data['body']);
        }

        if (isset($data['attributes']) && is_array($data['attributes'])) {
            foreach ($data['attributes'] as $attr) {
                if (is_array($attr)) {
                    $log->attributes[] = KeyValue::fromArray($attr);
                }
            }
        }

        return $log;
    }
}
