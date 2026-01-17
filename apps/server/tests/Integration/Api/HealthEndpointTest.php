<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Integration\AbstractIntegrationTestCase;

class HealthEndpointTest extends AbstractIntegrationTestCase
{
    public function testHealthEndpointIsPubliclyAccessible(): void
    {
        $this->browser()
            ->visit('/api/v1/health')
            ->assertSuccessful()
            ->assertStatus(200);
    }

    public function testHealthEndpointReturnsCorrectJsonFormat(): void
    {
        $this->browser()
            ->visit('/api/v1/health')
            ->assertSuccessful()
            ->assertJsonMatches('status', 'ok')
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $this->assertArrayHasKey('timestamp', $response);
            });
    }

    public function testHealthEndpointTimestampIsValidIso8601(): void
    {
        $this->browser()
            ->visit('/api/v1/health')
            ->assertSuccessful()
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $timestamp = $response['timestamp'];

                // Validate ISO 8601 format (ATOM format)
                $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp);

                $this->assertNotFalse($parsed, 'Timestamp should be valid ISO 8601 (ATOM) format');
                $this->assertSame($timestamp, $parsed->format(\DateTimeInterface::ATOM));
            });
    }

    public function testHealthEndpointDoesNotRequireApiKey(): void
    {
        // Make request without any authentication headers
        $this->browser()
            ->visit('/api/v1/health')
            ->assertSuccessful()
            ->assertStatus(200)
            ->assertJsonMatches('status', 'ok');
    }
}
