<?php

declare(strict_types=1);

namespace App\DTO\Otel\Trace;

use App\DTO\Otel\Common\KeyValue;

/**
 * Represents an event within a span.
 */
class SpanEvent
{
    /** @var string Unix nanoseconds since epoch as string */
    public string $timeUnixNano = '0';

    public string $name = '';

    /** @var list<KeyValue> */
    public array $attributes = [];

    public int $droppedAttributesCount = 0;

    /**
     * Get timestamp as milliseconds.
     */
    public function getTimestampMs(): float
    {
        return (float) $this->timeUnixNano / 1_000_000;
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
        $event = new self();
        $event->timeUnixNano = (string) ($data['timeUnixNano'] ?? '0');
        $event->name = (string) ($data['name'] ?? '');
        $event->droppedAttributesCount = (int) ($data['droppedAttributesCount'] ?? 0);

        if (isset($data['attributes']) && is_array($data['attributes'])) {
            foreach ($data['attributes'] as $attr) {
                if (is_array($attr)) {
                    $event->attributes[] = KeyValue::fromArray($attr);
                }
            }
        }

        return $event;
    }
}
