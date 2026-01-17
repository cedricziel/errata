<?php

declare(strict_types=1);

namespace App\DTO\Otel\Metrics;

/**
 * Represents a Sum metric (cumulative or delta).
 */
class Sum
{
    public const AGGREGATION_TEMPORALITY_UNSPECIFIED = 0;
    public const AGGREGATION_TEMPORALITY_DELTA = 1;
    public const AGGREGATION_TEMPORALITY_CUMULATIVE = 2;

    /** @var list<NumberDataPoint> */
    public array $dataPoints = [];

    public int $aggregationTemporality = self::AGGREGATION_TEMPORALITY_UNSPECIFIED;

    public bool $isMonotonic = false;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $sum = new self();
        $sum->aggregationTemporality = (int) (
            $data['aggregationTemporality'] ?? self::AGGREGATION_TEMPORALITY_UNSPECIFIED
        );
        $sum->isMonotonic = (bool) ($data['isMonotonic'] ?? false);

        if (isset($data['dataPoints']) && is_array($data['dataPoints'])) {
            foreach ($data['dataPoints'] as $point) {
                if (is_array($point)) {
                    $sum->dataPoints[] = NumberDataPoint::fromArray($point);
                }
            }
        }

        return $sum;
    }
}
