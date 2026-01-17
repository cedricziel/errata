<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\OtelExtension;
use PHPUnit\Framework\TestCase;

class OtelExtensionTest extends TestCase
{
    public function testGetGlobalsWithAllOptionsEnabled(): void
    {
        $extension = new OtelExtension(
            otelEnabled: true,
            otelApiKey: 'err_test_key_123',
            otelEndpoint: '/v1/traces',
            otelServiceName: 'errata-frontend',
            otelServiceVersion: '1.0.0',
        );

        $globals = $extension->getGlobals();

        $this->assertSame('true', $globals['otel_enabled']);
        $this->assertSame('err_test_key_123', $globals['otel_api_key']);
        $this->assertSame('/v1/traces', $globals['otel_endpoint']);
        $this->assertSame('errata-frontend', $globals['otel_service_name']);
        $this->assertSame('1.0.0', $globals['otel_service_version']);
    }

    public function testGetGlobalsWithDisabled(): void
    {
        $extension = new OtelExtension(
            otelEnabled: false,
            otelApiKey: '',
            otelEndpoint: '/v1/traces',
            otelServiceName: 'errata-frontend',
            otelServiceVersion: '1.0.0',
        );

        $globals = $extension->getGlobals();

        $this->assertSame('false', $globals['otel_enabled']);
        $this->assertSame('', $globals['otel_api_key']);
    }

    public function testGetGlobalsWithNullApiKey(): void
    {
        $extension = new OtelExtension(
            otelEnabled: false,
            otelApiKey: null,
            otelEndpoint: '/v1/traces',
            otelServiceName: 'errata-frontend',
            otelServiceVersion: '1.0.0',
        );

        $globals = $extension->getGlobals();

        $this->assertNull($globals['otel_api_key']);
    }

    public function testGetGlobalsWithCustomEndpoint(): void
    {
        $extension = new OtelExtension(
            otelEnabled: true,
            otelApiKey: 'err_test_key_123',
            otelEndpoint: 'https://otel.example.com/v1/traces',
            otelServiceName: 'my-custom-app',
            otelServiceVersion: '2.5.0',
        );

        $globals = $extension->getGlobals();

        $this->assertSame('https://otel.example.com/v1/traces', $globals['otel_endpoint']);
        $this->assertSame('my-custom-app', $globals['otel_service_name']);
        $this->assertSame('2.5.0', $globals['otel_service_version']);
    }
}
