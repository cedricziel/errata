<?php

declare(strict_types=1);

namespace App\DTO\Otel\Metrics;

use App\DTO\Otel\Common\InstrumentationScope;

/**
 * Represents a collection of metrics from a single instrumentation scope.
 */
class ScopeMetrics
{
    public ?InstrumentationScope $scope = null;

    /** @var list<Metric> */
    public array $metrics = [];

    public ?string $schemaUrl = null;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $scopeMetrics = new self();
        $scopeMetrics->schemaUrl = isset($data['schemaUrl']) ? (string) $data['schemaUrl'] : null;

        if (isset($data['scope']) && is_array($data['scope'])) {
            $scopeMetrics->scope = InstrumentationScope::fromArray($data['scope']);
        }

        if (isset($data['metrics']) && is_array($data['metrics'])) {
            foreach ($data['metrics'] as $metric) {
                if (is_array($metric)) {
                    $scopeMetrics->metrics[] = Metric::fromArray($metric);
                }
            }
        }

        return $scopeMetrics;
    }
}
