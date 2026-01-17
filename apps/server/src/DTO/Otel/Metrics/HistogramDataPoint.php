<?php

declare(strict_types=1);

namespace App\DTO\Otel\Metrics;

use App\DTO\Otel\Common\KeyValue;

/**
 * Represents a histogram data point.
 */
class HistogramDataPoint
{
    /** @var list<KeyValue> */
    public array $attributes = [];

    /** @var string Unix nanoseconds since epoch as string */
    public string $startTimeUnixNano = '0';

    /** @var string Unix nanoseconds since epoch as string */
    public string $timeUnixNano = '0';

    public int $count = 0;

    public ?float $sum = null;

    /** @var list<int> */
    public array $bucketCounts = [];

    /** @var list<float> */
    public array $explicitBounds = [];

    /** @var list<Exemplar> */
    public array $exemplars = [];

    public int $flags = 0;

    public ?float $min = null;

    public ?float $max = null;

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
        $point->count = (int) ($data['count'] ?? 0);
        $point->sum = isset($data['sum']) ? (float) $data['sum'] : null;
        $point->min = isset($data['min']) ? (float) $data['min'] : null;
        $point->max = isset($data['max']) ? (float) $data['max'] : null;
        $point->flags = (int) ($data['flags'] ?? 0);

        if (isset($data['bucketCounts']) && is_array($data['bucketCounts'])) {
            $point->bucketCounts = array_map('intval', $data['bucketCounts']);
        }

        if (isset($data['explicitBounds']) && is_array($data['explicitBounds'])) {
            $point->explicitBounds = array_map('floatval', $data['explicitBounds']);
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
