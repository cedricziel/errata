<?php

declare(strict_types=1);

namespace App\DTO\Otel\Trace;

use App\DTO\Otel\Common\KeyValue;

/**
 * Represents an OTLP Span.
 */
class Span
{
    public const KIND_UNSPECIFIED = 0;
    public const KIND_INTERNAL = 1;
    public const KIND_SERVER = 2;
    public const KIND_CLIENT = 3;
    public const KIND_PRODUCER = 4;
    public const KIND_CONSUMER = 5;

    public string $traceId = '';

    public string $spanId = '';

    public ?string $traceState = null;

    public ?string $parentSpanId = null;

    public string $name = '';

    public int $kind = self::KIND_UNSPECIFIED;

    /** @var string Unix nanoseconds since epoch as string */
    public string $startTimeUnixNano = '0';

    /** @var string Unix nanoseconds since epoch as string */
    public string $endTimeUnixNano = '0';

    /** @var list<KeyValue> */
    public array $attributes = [];

    public int $droppedAttributesCount = 0;

    /** @var list<SpanEvent> */
    public array $events = [];

    public int $droppedEventsCount = 0;

    /** @var list<SpanLink> */
    public array $links = [];

    public int $droppedLinksCount = 0;

    public ?SpanStatus $status = null;

    /**
     * Calculate duration in milliseconds.
     */
    public function getDurationMs(): float
    {
        $startNano = (float) $this->startTimeUnixNano;
        $endNano = (float) $this->endTimeUnixNano;

        return ($endNano - $startNano) / 1_000_000;
    }

    /**
     * Get start timestamp as milliseconds since epoch.
     */
    public function getStartTimestampMs(): float
    {
        return (float) $this->startTimeUnixNano / 1_000_000;
    }

    /**
     * Get span kind as a string.
     */
    public function getKindString(): string
    {
        return match ($this->kind) {
            self::KIND_INTERNAL => 'internal',
            self::KIND_SERVER => 'server',
            self::KIND_CLIENT => 'client',
            self::KIND_PRODUCER => 'producer',
            self::KIND_CONSUMER => 'consumer',
            default => 'unspecified',
        };
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
     * Get events as an array.
     *
     * @return list<array<string, mixed>>
     */
    public function getEventsAsArray(): array
    {
        return array_map(static fn (SpanEvent $e) => [
            'name' => $e->name,
            'timestamp_ms' => $e->getTimestampMs(),
            'attributes' => $e->getAttributesAsArray(),
        ], $this->events);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $span = new self();
        $span->traceId = strtolower((string) ($data['traceId'] ?? ''));
        $span->spanId = strtolower((string) ($data['spanId'] ?? ''));
        $span->traceState = isset($data['traceState']) ? (string) $data['traceState'] : null;
        $span->parentSpanId = isset($data['parentSpanId']) ? strtolower((string) $data['parentSpanId']) : null;
        $span->name = (string) ($data['name'] ?? '');
        $span->kind = (int) ($data['kind'] ?? self::KIND_UNSPECIFIED);
        $span->startTimeUnixNano = (string) ($data['startTimeUnixNano'] ?? '0');
        $span->endTimeUnixNano = (string) ($data['endTimeUnixNano'] ?? '0');
        $span->droppedAttributesCount = (int) ($data['droppedAttributesCount'] ?? 0);
        $span->droppedEventsCount = (int) ($data['droppedEventsCount'] ?? 0);
        $span->droppedLinksCount = (int) ($data['droppedLinksCount'] ?? 0);

        if (isset($data['attributes']) && is_array($data['attributes'])) {
            foreach ($data['attributes'] as $attr) {
                if (is_array($attr)) {
                    $span->attributes[] = KeyValue::fromArray($attr);
                }
            }
        }

        if (isset($data['events']) && is_array($data['events'])) {
            foreach ($data['events'] as $event) {
                if (is_array($event)) {
                    $span->events[] = SpanEvent::fromArray($event);
                }
            }
        }

        if (isset($data['links']) && is_array($data['links'])) {
            foreach ($data['links'] as $link) {
                if (is_array($link)) {
                    $span->links[] = SpanLink::fromArray($link);
                }
            }
        }

        if (isset($data['status']) && is_array($data['status'])) {
            $span->status = SpanStatus::fromArray($data['status']);
        }

        return $span;
    }
}
