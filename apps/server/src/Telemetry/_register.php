<?php

declare(strict_types=1);

use App\Telemetry\UrlFilteringSamplerFactory;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\ExporterFactory;
use OpenTelemetry\SDK\Trace\SpanProcessorFactory;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;

// Only register if SDK is not disabled and our custom sampler is enabled
if (Sdk::isDisabled()) {
    return;
}

// Check if custom URL filtering is enabled via env
$excludedUrls = $_ENV['OTEL_PHP_EXCLUDED_URLS'] ?? $_SERVER['OTEL_PHP_EXCLUDED_URLS'] ?? '';
if ('' === $excludedUrls) {
    return;
}

// Register our initializer to override the TracerProvider with our custom sampler
// This runs after the SDK's initializer in the chain
Globals::registerInitializer(static function (Configurator $configurator): Configurator {
    $excludedUrls = $_ENV['OTEL_PHP_EXCLUDED_URLS'] ?? $_SERVER['OTEL_PHP_EXCLUDED_URLS'] ?? '';
    $patterns = array_filter(array_map('trim', explode(',', $excludedUrls)));

    if ([] === $patterns) {
        return $configurator;
    }

    // Build our TracerProvider with custom URL-filtering sampler
    $resource = ResourceInfoFactory::defaultResource();
    $exporter = (new ExporterFactory())->create();
    $spanProcessor = (new SpanProcessorFactory())->create($exporter, null);
    $sampler = UrlFilteringSamplerFactory::create($patterns);

    $tracerProvider = (new TracerProviderBuilder())
        ->addSpanProcessor($spanProcessor)
        ->setResource($resource)
        ->setSampler($sampler)
        ->build();

    ShutdownHandler::register($tracerProvider->shutdown(...));

    // Override just the TracerProvider, keep everything else from SDK
    return $configurator->withTracerProvider($tracerProvider);
});
