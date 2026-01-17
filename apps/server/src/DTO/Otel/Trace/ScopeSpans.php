<?php

declare(strict_types=1);

namespace App\DTO\Otel\Trace;

use App\DTO\Otel\Common\InstrumentationScope;

/**
 * Represents a collection of spans from a single instrumentation scope.
 */
class ScopeSpans
{
    public ?InstrumentationScope $scope = null;

    /** @var list<Span> */
    public array $spans = [];

    public ?string $schemaUrl = null;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $scopeSpans = new self();
        $scopeSpans->schemaUrl = isset($data['schemaUrl']) ? (string) $data['schemaUrl'] : null;

        if (isset($data['scope']) && is_array($data['scope'])) {
            $scopeSpans->scope = InstrumentationScope::fromArray($data['scope']);
        }

        if (isset($data['spans']) && is_array($data['spans'])) {
            foreach ($data['spans'] as $span) {
                if (is_array($span)) {
                    $scopeSpans->spans[] = Span::fromArray($span);
                }
            }
        }

        return $scopeSpans;
    }
}
