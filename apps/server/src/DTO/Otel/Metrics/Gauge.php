<?php

declare(strict_types=1);

namespace App\DTO\Otel\Metrics;

/**
 * Represents a Gauge metric (instantaneous value).
 */
class Gauge
{
    /** @var list<NumberDataPoint> */
    public array $dataPoints = [];

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $gauge = new self();

        if (isset($data['dataPoints']) && is_array($data['dataPoints'])) {
            foreach ($data['dataPoints'] as $point) {
                if (is_array($point)) {
                    $gauge->dataPoints[] = NumberDataPoint::fromArray($point);
                }
            }
        }

        return $gauge;
    }
}
