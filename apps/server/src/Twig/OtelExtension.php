<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Twig extension that provides OpenTelemetry configuration as global variables.
 *
 * These variables are used to configure the frontend OTel instrumentation
 * via meta tags in the base template.
 */
class OtelExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly bool $otelEnabled,
        private readonly ?string $otelApiKey,
        private readonly string $otelEndpoint,
        private readonly string $otelServiceName,
        private readonly string $otelServiceVersion,
    ) {
    }

    public function getGlobals(): array
    {
        return [
            'otel_enabled' => $this->otelEnabled ? 'true' : 'false',
            'otel_api_key' => $this->otelApiKey,
            'otel_endpoint' => $this->otelEndpoint,
            'otel_service_name' => $this->otelServiceName,
            'otel_service_version' => $this->otelServiceVersion,
        ];
    }
}
