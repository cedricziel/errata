<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * Integration tests for OpenTelemetry frontend configuration meta tags.
 */
class OtelExtensionIntegrationTest extends WebTestCase
{
    use HasBrowser;

    public function testOtelMetaTagsAreRenderedOnLoginPage(): void
    {
        $html = $this->browser()
            ->visit('/login')
            ->assertSuccessful()
            ->content();

        // Check that the otel-enabled meta tag is present (default is false)
        $this->assertStringContainsString('<meta name="otel-enabled" content="false">', $html);

        // Check endpoint meta tag
        $this->assertStringContainsString('<meta name="otel-endpoint" content="/v1/traces">', $html);

        // Check service name meta tag
        $this->assertStringContainsString('<meta name="otel-service-name" content="errata-frontend">', $html);

        // Check service version meta tag
        $this->assertStringContainsString('<meta name="otel-service-version" content="1.0.0">', $html);
    }

    public function testOtelApiKeyMetaTagNotRenderedWhenEmpty(): void
    {
        // When API key is empty, the meta tag should not be rendered
        $html = $this->browser()
            ->visit('/login')
            ->assertSuccessful()
            ->content();

        // The otel-api-key meta tag should not be present when empty
        $this->assertStringNotContainsString('name="otel-api-key"', $html);
    }
}
