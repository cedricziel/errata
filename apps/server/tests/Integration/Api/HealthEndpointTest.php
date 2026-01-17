<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Integration\AbstractIntegrationTestCase;

class HealthEndpointTest extends AbstractIntegrationTestCase
{
    public function testHealthEndpointIsPubliclyAccessible(): void
    {
        $this->client->request('GET', '/api/v1/health');

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(200);
    }

    public function testHealthEndpointReturnsCorrectJsonFormat(): void
    {
        $this->client->request('GET', '/api/v1/health');

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('status', $response);
        $this->assertSame('ok', $response['status']);
        $this->assertArrayHasKey('timestamp', $response);
    }

    public function testHealthEndpointTimestampIsValidIso8601(): void
    {
        $this->client->request('GET', '/api/v1/health');

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);

        $timestamp = $response['timestamp'];

        // Validate ISO 8601 format (ATOM format)
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp);

        $this->assertNotFalse($parsed, 'Timestamp should be valid ISO 8601 (ATOM) format');
        $this->assertSame($timestamp, $parsed->format(\DateTimeInterface::ATOM));
    }

    public function testHealthEndpointDoesNotRequireApiKey(): void
    {
        // Make request without any authentication headers
        $this->client->request('GET', '/api/v1/health');

        // Should succeed without authentication
        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(200);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('ok', $response['status']);
    }
}
