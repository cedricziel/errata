<?php

declare(strict_types=1);

namespace App\Telemetry;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;

/**
 * A sampler that filters out requests to specific URL patterns.
 *
 * Unlike OTEL_PHP_EXCLUDED_URLS which only checks once per PHP-FPM worker process,
 * this sampler checks the URL on every sampling decision, making it work correctly
 * with persistent PHP processes.
 *
 * This prevents recursive tracing loops when the application sends OTLP data to itself.
 */
final class UrlFilteringSampler implements SamplerInterface
{
    /**
     * @param SamplerInterface $innerSampler     The sampler to delegate to for non-excluded URLs
     * @param array<string>    $excludedPatterns Regex patterns to exclude (matched against REQUEST_URI)
     */
    public function __construct(
        private readonly SamplerInterface $innerSampler,
        private readonly array $excludedPatterns = [],
    ) {
    }

    public function shouldSample(
        ContextInterface $parentContext,
        string $traceId,
        string $spanName,
        int $spanKind,
        AttributesInterface $attributes,
        array $links,
    ): SamplingResult {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($requestUri, PHP_URL_PATH) ?? $requestUri;

        foreach ($this->excludedPatterns as $pattern) {
            // Support both plain strings and regex patterns
            if (str_starts_with($pattern, '/') && str_ends_with($pattern, '/')) {
                // It's a regex
                if (1 === preg_match($pattern, $path)) {
                    return new SamplingResult(SamplingResult::DROP);
                }
            } else {
                // Plain string - check if path contains it
                if (str_contains($path, $pattern)) {
                    return new SamplingResult(SamplingResult::DROP);
                }
            }
        }

        return $this->innerSampler->shouldSample(
            $parentContext,
            $traceId,
            $spanName,
            $spanKind,
            $attributes,
            $links,
        );
    }

    public function getDescription(): string
    {
        return sprintf('UrlFilteringSampler{%s,patterns=%d}',
            $this->innerSampler->getDescription(),
            count($this->excludedPatterns)
        );
    }
}
