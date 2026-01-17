<?php

declare(strict_types=1);

namespace App\DTO\Otel\Metrics;

/**
 * Represents an OTLP ExportMetricsServiceRequest.
 */
class ExportMetricsServiceRequest
{
    /** @var list<ResourceMetrics> */
    public array $resourceMetrics = [];

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $request = new self();

        if (isset($data['resourceMetrics']) && is_array($data['resourceMetrics'])) {
            foreach ($data['resourceMetrics'] as $resourceMetrics) {
                if (is_array($resourceMetrics)) {
                    $request->resourceMetrics[] = ResourceMetrics::fromArray($resourceMetrics);
                }
            }
        }

        return $request;
    }
}
