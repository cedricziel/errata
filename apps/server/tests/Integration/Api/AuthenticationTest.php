<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\ApiKey;
use App\Security\ApiKeyAuthenticator;
use App\Tests\Integration\AbstractIntegrationTestCase;
use Zenstruck\Browser\HttpOptions;

class AuthenticationTest extends AbstractIntegrationTestCase
{
    public function testRequestWithoutApiKeyReturns401(): void
    {
        // When no X-Errata-Key header is present, ApiKeyAuthenticator::supports() returns false,
        // so Symfony's security access control denies access with a 401.
        // The response format depends on security exception handling, not the authenticator.
        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($this->createValidEventPayload()))
            ->assertStatus(401);
    }

    public function testRequestWithInvalidApiKeyReturns401(): void
    {
        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($this->createValidEventPayload())
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, 'invalid_api_key'))
            ->assertStatus(401)
            ->assertJsonMatches('error', 'authentication_failed');
    }

    public function testRequestWithExpiredApiKeyReturns401(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $keyData = $this->createTestApiKey(
            $project,
            [ApiKey::SCOPE_INGEST],
            ApiKey::ENV_DEVELOPMENT,
            true,
            new \DateTimeImmutable('-1 day')
        );

        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($this->createValidEventPayload())
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $keyData['plainKey']))
            ->assertStatus(401)
            ->assertJsonMatches('error', 'authentication_failed');
    }

    public function testRequestWithInactiveApiKeyReturns401(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $keyData = $this->createTestApiKey(
            $project,
            [ApiKey::SCOPE_INGEST],
            ApiKey::ENV_DEVELOPMENT,
            false
        );

        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($this->createValidEventPayload())
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $keyData['plainKey']))
            ->assertStatus(401)
            ->assertJsonMatches('error', 'authentication_failed');
    }

    public function testRequestWithoutIngestScopeReturns401(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $keyData = $this->createTestApiKey(
            $project,
            [ApiKey::SCOPE_READ],
            ApiKey::ENV_DEVELOPMENT,
            true
        );

        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($this->createValidEventPayload())
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $keyData['plainKey']))
            ->assertStatus(401)
            ->assertJsonMatches('error', 'authentication_failed');
    }

    public function testRequestWithValidApiKeySucceeds(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $keyData = $this->createTestApiKey($project);

        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($this->createValidEventPayload())
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $keyData['plainKey']))
            ->assertStatus(202)
            ->assertJsonMatches('status', 'accepted');
    }

    public function testApiKeyLastUsedAtUpdatesOnUse(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $keyData = $this->createTestApiKey($project);
        $apiKeyId = $keyData['apiKey']->getId();

        $this->assertNull($keyData['apiKey']->getLastUsedAt());

        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($this->createValidEventPayload())
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $keyData['plainKey']))
            ->assertStatus(202);

        // Re-fetch the entity from the database
        $apiKey = $this->apiKeyRepository->find($apiKeyId);

        $this->assertNotNull($apiKey->getLastUsedAt());
    }

    public function testEmptyApiKeyHeaderReturns401(): void
    {
        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($this->createValidEventPayload())
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, ''))
            ->assertStatus(401)
            ->assertJsonMatches('error', 'authentication_failed');
    }
}
