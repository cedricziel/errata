<?php

declare(strict_types=1);

namespace App\DTO\Otel\Metrics;

use App\DTO\Otel\Common\Resource;

/**
 * Represents a collection of scope metrics from a single resource.
 */
class ResourceMetrics
{
    public ?Resource $resource = null;

    /** @var list<ScopeMetrics> */
    public array $scopeMetrics = [];

    public ?string $schemaUrl = null;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $resourceMetrics = new self();
        $resourceMetrics->schemaUrl = isset($data['schemaUrl']) ? (string) $data['schemaUrl'] : null;

        if (isset($data['resource']) && is_array($data['resource'])) {
            $resourceMetrics->resource = Resource::fromArray($data['resource']);
        }

        if (isset($data['scopeMetrics']) && is_array($data['scopeMetrics'])) {
            foreach ($data['scopeMetrics'] as $scopeMetrics) {
                if (is_array($scopeMetrics)) {
                    $resourceMetrics->scopeMetrics[] = ScopeMetrics::fromArray($scopeMetrics);
                }
            }
        }

        return $resourceMetrics;
    }
}
