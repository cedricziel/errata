<?php

declare(strict_types=1);

namespace App\DTO\Otel\Trace;

use App\DTO\Otel\Common\Resource;

/**
 * Represents a collection of scope spans from a single resource.
 */
class ResourceSpans
{
    public ?Resource $resource = null;

    /** @var list<ScopeSpans> */
    public array $scopeSpans = [];

    public ?string $schemaUrl = null;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $resourceSpans = new self();
        $resourceSpans->schemaUrl = isset($data['schemaUrl']) ? (string) $data['schemaUrl'] : null;

        if (isset($data['resource']) && is_array($data['resource'])) {
            $resourceSpans->resource = Resource::fromArray($data['resource']);
        }

        if (isset($data['scopeSpans']) && is_array($data['scopeSpans'])) {
            foreach ($data['scopeSpans'] as $scopeSpans) {
                if (is_array($scopeSpans)) {
                    $resourceSpans->scopeSpans[] = ScopeSpans::fromArray($scopeSpans);
                }
            }
        }

        return $resourceSpans;
    }
}
