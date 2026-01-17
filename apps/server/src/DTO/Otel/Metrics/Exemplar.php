<?php

declare(strict_types=1);

namespace App\DTO\Otel\Metrics;

use App\DTO\Otel\Common\KeyValue;

/**
 * Represents an exemplar (sample) within a metric.
 */
class Exemplar
{
    /** @var list<KeyValue> */
    public array $filteredAttributes = [];

    /** @var string Unix nanoseconds since epoch as string */
    public string $timeUnixNano = '0';

    public ?int $asInt = null;

    public ?float $asDouble = null;

    public ?string $spanId = null;

    public ?string $traceId = null;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $exemplar = new self();
        $exemplar->timeUnixNano = (string) ($data['timeUnixNano'] ?? '0');
        $exemplar->spanId = isset($data['spanId']) ? strtolower((string) $data['spanId']) : null;
        $exemplar->traceId = isset($data['traceId']) ? strtolower((string) $data['traceId']) : null;

        if (isset($data['asInt'])) {
            $exemplar->asInt = (int) $data['asInt'];
        }
        if (isset($data['asDouble'])) {
            $exemplar->asDouble = (float) $data['asDouble'];
        }

        if (isset($data['filteredAttributes']) && is_array($data['filteredAttributes'])) {
            foreach ($data['filteredAttributes'] as $attr) {
                if (is_array($attr)) {
                    $exemplar->filteredAttributes[] = KeyValue::fromArray($attr);
                }
            }
        }

        return $exemplar;
    }
}
