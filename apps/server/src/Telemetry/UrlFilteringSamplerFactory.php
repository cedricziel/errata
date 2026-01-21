<?php

declare(strict_types=1);

namespace App\Telemetry;

use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SamplerInterface;

/**
 * Factory for creating URL-filtering samplers.
 */
final class UrlFilteringSamplerFactory
{
    /**
     * Create a URL-filtering sampler wrapping the default ParentBased(AlwaysOn) sampler.
     *
     * @param array<string> $excludedPatterns URL patterns to exclude from tracing
     */
    public static function create(array $excludedPatterns): SamplerInterface
    {
        $innerSampler = new ParentBased(new AlwaysOnSampler());

        return new UrlFilteringSampler($innerSampler, $excludedPatterns);
    }
}
