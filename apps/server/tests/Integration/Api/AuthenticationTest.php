<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\ApiKey;
use App\Security\ApiKeyAuthenticator;
use App\Tests\Integration\AbstractIntegrationTestCase;

class AuthenticationTest extends AbstractIntegrationTestCase
{
    public function testRequestWithoutApiKeyReturns401(): void
    {
        // When no X-Errata-Key header is present, ApiKeyAuthenticator::supports() returns false,
        // so Symfony's security access control denies access with a 401.
        // The response format depends on security exception handling, not the authenticator.
        $this->client->request(
            'POST',
            '/api/v1/events',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($this->createValidEventPayload())
        );

        $this->assertResponseStatusCodeSame(401);
    }

    public function testRequestWithInvalidApiKeyReturns401(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/events',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ApiKeyAuthenticator::HEADER_NAME)) => 'invalid_api_key',
            ],
            json_encode($this->createValidEventPayload())
        );

        $this->assertResponseStatusCodeSame(401);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame('authentication_failed', $response['error']);
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

        $this->client->request(
            'POST',
            '/api/v1/events',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ApiKeyAuthenticator::HEADER_NAME)) => $keyData['plainKey'],
            ],
            json_encode($this->createValidEventPayload())
        );

        $this->assertResponseStatusCodeSame(401);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame('authentication_failed', $response['error']);
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

        $this->client->request(
            'POST',
            '/api/v1/events',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ApiKeyAuthenticator::HEADER_NAME)) => $keyData['plainKey'],
            ],
            json_encode($this->createValidEventPayload())
        );

        $this->assertResponseStatusCodeSame(401);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame('authentication_failed', $response['error']);
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

        $this->client->request(
            'POST',
            '/api/v1/events',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ApiKeyAuthenticator::HEADER_NAME)) => $keyData['plainKey'],
            ],
            json_encode($this->createValidEventPayload())
        );

        $this->assertResponseStatusCodeSame(401);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame('authentication_failed', $response['error']);
    }

    public function testRequestWithValidApiKeySucceeds(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $keyData = $this->createTestApiKey($project);

        $this->client->request(
            'POST',
            '/api/v1/events',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ApiKeyAuthenticator::HEADER_NAME)) => $keyData['plainKey'],
            ],
            json_encode($this->createValidEventPayload())
        );

        $this->assertResponseStatusCodeSame(202);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('accepted', $response['status']);
    }

    public function testApiKeyLastUsedAtUpdatesOnUse(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $keyData = $this->createTestApiKey($project);

        $this->assertNull($keyData['apiKey']->getLastUsedAt());

        $this->client->request(
            'POST',
            '/api/v1/events',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ApiKeyAuthenticator::HEADER_NAME)) => $keyData['plainKey'],
            ],
            json_encode($this->createValidEventPayload())
        );

        $this->assertResponseStatusCodeSame(202);

        // Refresh the entity from the database
        $this->entityManager->refresh($keyData['apiKey']);

        $this->assertNotNull($keyData['apiKey']->getLastUsedAt());
    }

    public function testEmptyApiKeyHeaderReturns401(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/events',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ApiKeyAuthenticator::HEADER_NAME)) => '',
            ],
            json_encode($this->createValidEventPayload())
        );

        $this->assertResponseStatusCodeSame(401);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame('authentication_failed', $response['error']);
    }
}
