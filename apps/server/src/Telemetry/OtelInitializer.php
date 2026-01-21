<?php

declare(strict_types=1);

namespace App\Telemetry;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Propagation\PropagatorFactory;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SpanProcessorFactory;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;

/**
 * Initializes OpenTelemetry SDK with a custom URL-filtering sampler.
 *
 * This replaces the default SDK autoloader initialization to use a sampler
 * that checks the request URL on every sampling decision, preventing
 * recursive tracing loops when sending OTLP data to ourselves.
 */
final class OtelInitializer
{
    private static bool $initialized = false;

    /**
     * @param array<string> $excludedUrlPatterns URL patterns to exclude from tracing
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly array $excludedUrlPatterns,
    ) {
    }

    /**
     * Initialize OpenTelemetry SDK with custom sampler.
     * Safe to call multiple times - will only initialize once.
     */
    public function initialize(): void
    {
        if (self::$initialized || !$this->enabled) {
            return;
        }
        self::$initialized = true;

        Globals::registerInitializer(fn (Configurator $configurator) => $this->configure($configurator));
    }

    private function configure(Configurator $configurator): Configurator
    {
        $propagator = (new PropagatorFactory())->create();

        // Check if SDK is disabled via OTEL_SDK_DISABLED
        if (filter_var($_ENV['OTEL_SDK_DISABLED'] ?? $_SERVER['OTEL_SDK_DISABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN)) {
            return $configurator->withPropagator($propagator);
        }

        $resource = ResourceInfoFactory::defaultResource();
        $exporter = (new ExporterFactory())->create();
        $spanProcessor = (new SpanProcessorFactory())->create($exporter, null);

        // Create our custom sampler that wraps the default ParentBased(AlwaysOn)
        $innerSampler = new ParentBased(new AlwaysOnSampler());
        $sampler = new UrlFilteringSampler($innerSampler, $this->excludedUrlPatterns);

        $tracerProvider = (new TracerProviderBuilder())
            ->addSpanProcessor($spanProcessor)
            ->setResource($resource)
            ->setSampler($sampler)
            ->build();

        ShutdownHandler::register($tracerProvider->shutdown(...));

        return $configurator
            ->withTracerProvider($tracerProvider)
            ->withPropagator($propagator);
    }
}
