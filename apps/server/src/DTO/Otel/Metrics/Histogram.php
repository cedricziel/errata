<?php

declare(strict_types=1);

namespace App\DTO\Otel\Metrics;

/**
 * Represents a Histogram metric.
 */
class Histogram
{
    public const AGGREGATION_TEMPORALITY_UNSPECIFIED = 0;
    public const AGGREGATION_TEMPORALITY_DELTA = 1;
    public const AGGREGATION_TEMPORALITY_CUMULATIVE = 2;

    /** @var list<HistogramDataPoint> */
    public array $dataPoints = [];

    public int $aggregationTemporality = self::AGGREGATION_TEMPORALITY_UNSPECIFIED;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $histogram = new self();
        $histogram->aggregationTemporality = (int) (
            $data['aggregationTemporality'] ?? self::AGGREGATION_TEMPORALITY_UNSPECIFIED
        );

        if (isset($data['dataPoints']) && is_array($data['dataPoints'])) {
            foreach ($data['dataPoints'] as $point) {
                if (is_array($point)) {
                    $histogram->dataPoints[] = HistogramDataPoint::fromArray($point);
                }
            }
        }

        return $histogram;
    }
}
