<?php

declare(strict_types=1);

namespace App\DTO\Otel\Metrics;

use App\DTO\Otel\Common\KeyValue;

/**
 * Represents a single data point in a timeseries.
 */
class NumberDataPoint
{
    /** @var list<KeyValue> */
    public array $attributes = [];

    /** @var string Unix nanoseconds since epoch as string */
    public string $startTimeUnixNano = '0';

    /** @var string Unix nanoseconds since epoch as string */
    public string $timeUnixNano = '0';

    public ?int $asInt = null;

    public ?float $asDouble = null;

    /** @var list<Exemplar> */
    public array $exemplars = [];

    public int $flags = 0;

    /**
     * Get the value (int or double).
     */
    public function getValue(): float|int|null
    {
        return $this->asDouble ?? $this->asInt;
    }

    /**
     * Get timestamp as milliseconds since epoch.
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
        $point = new self();
        $point->startTimeUnixNano = (string) ($data['startTimeUnixNano'] ?? '0');
        $point->timeUnixNano = (string) ($data['timeUnixNano'] ?? '0');
        $point->flags = (int) ($data['flags'] ?? 0);

        if (isset($data['asInt'])) {
            $point->asInt = (int) $data['asInt'];
        }
        if (isset($data['asDouble'])) {
            $point->asDouble = (float) $data['asDouble'];
        }

        if (isset($data['attributes']) && is_array($data['attributes'])) {
            foreach ($data['attributes'] as $attr) {
                if (is_array($attr)) {
                    $point->attributes[] = KeyValue::fromArray($attr);
                }
            }
        }

        if (isset($data['exemplars']) && is_array($data['exemplars'])) {
            foreach ($data['exemplars'] as $exemplar) {
                if (is_array($exemplar)) {
                    $point->exemplars[] = Exemplar::fromArray($exemplar);
                }
            }
        }

        return $point;
    }
}
