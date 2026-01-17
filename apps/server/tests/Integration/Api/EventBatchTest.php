<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Security\ApiKeyAuthenticator;
use App\Tests\Integration\AbstractIntegrationTestCase;

class EventBatchTest extends AbstractIntegrationTestCase
{
    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $keyData = $this->createTestApiKey($project);
        $this->apiKey = $keyData['plainKey'];
    }

    public function testBatchOfValidEventsReturns202(): void
    {
        $events = [
            $this->createValidEventPayload(['message' => 'Error 1']),
            $this->createValidEventPayload(['message' => 'Error 2']),
            $this->createValidEventPayload(['message' => 'Error 3']),
        ];

        $this->client->request(
            'POST',
            '/api/v1/events/batch',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ApiKeyAuthenticator::HEADER_NAME)) => $this->apiKey,
            ],
            json_encode(['events' => $events])
        );

        $this->assertResponseStatusCodeSame(202);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('accepted', $response['status']);
        $this->assertSame(3, $response['accepted']);
        $this->assertSame(3, $response['total']);
        $this->assertArrayNotHasKey('errors', $response);
    }

    public function testDirectArrayFormatWorks(): void
    {
        $events = [
            $this->createValidEventPayload(['message' => 'Error 1']),
            $this->createValidEventPayload(['message' => 'Error 2']),
        ];

        $this->client->request(
            'POST',
            '/api/v1/events/batch',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ApiKeyAuthenticator::HEADER_NAME)) => $this->apiKey,
            ],
            json_encode($events)
        );

        $this->assertResponseStatusCodeSame(202);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('accepted', $response['status']);
        $this->assertSame(2, $response['accepted']);
        $this->assertSame(2, $response['total']);
    }

    public function testBatchExceeding100EventsReturns400(): void
    {
        $events = [];
        for ($i = 0; $i < 101; ++$i) {
            $events[] = $this->createValidEventPayload(['message' => "Error $i"]);
        }

        $this->client->request(
            'POST',
            '/api/v1/events/batch',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ApiKeyAuthenticator::HEADER_NAME)) => $this->apiKey,
            ],
            json_encode(['events' => $events])
        );

        $this->assertResponseStatusCodeSame(400);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('bad_request', $response['error']);
        $this->assertStringContainsString('100', $response['message']);
    }

    public function testPartialValidationErrorsValidEventsAccepted(): void
    {
        $events = [
            $this->createValidEventPayload(['message' => 'Valid error 1']),
            ['event_type' => 'invalid_type', 'message' => 'Invalid event'],
            $this->createValidEventPayload(['message' => 'Valid error 2']),
        ];

        $this->client->request(
            'POST',
            '/api/v1/events/batch',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ApiKeyAuthenticator::HEADER_NAME)) => $this->apiKey,
            ],
            json_encode(['events' => $events])
        );

        $this->assertResponseStatusCodeSame(202);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('accepted', $response['status']);
        $this->assertSame(2, $response['accepted']);
        $this->assertSame(3, $response['total']);
        $this->assertArrayHasKey('errors', $response);
        $this->assertArrayHasKey(1, $response['errors']);
    }

    public function testEmptyBatchReturns400(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/events/batch',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ApiKeyAuthenticator::HEADER_NAME)) => $this->apiKey,
            ],
            json_encode(['events' => []])
        );

        $this->assertResponseStatusCodeSame(400);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('bad_request', $response['error']);
    }

    public function testInvalidJsonReturns400(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/events/batch',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ApiKeyAuthenticator::HEADER_NAME)) => $this->apiKey,
            ],
            'not valid json'
        );

        $this->assertResponseStatusCodeSame(400);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('bad_request', $response['error']);
    }

    public function testMixedEventTypesInBatchWork(): void
    {
        $events = [
            $this->createValidEventPayload(['event_type' => 'crash', 'message' => 'App crashed']),
            $this->createValidEventPayload(['event_type' => 'error', 'message' => 'Error occurred']),
            $this->createValidEventPayload(['event_type' => 'log', 'message' => 'Log message']),
            $this->createValidEventPayload([
                'event_type' => 'metric', 'metric_name' => 'response_time', 'metric_value' => 150.5,
            ]),
            $this->createValidEventPayload(['event_type' => 'span', 'operation' => 'api_call', 'duration_ms' => 200.0]),
        ];

        $this->client->request(
            'POST',
            '/api/v1/events/batch',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ApiKeyAuthenticator::HEADER_NAME)) => $this->apiKey,
            ],
            json_encode(['events' => $events])
        );

        $this->assertResponseStatusCodeSame(202);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('accepted', $response['status']);
        $this->assertSame(5, $response['accepted']);
        $this->assertSame(5, $response['total']);
    }

    public function testExactly100EventsSucceeds(): void
    {
        $events = [];
        for ($i = 0; $i < 100; ++$i) {
            $events[] = $this->createValidEventPayload(['message' => "Error $i"]);
        }

        $this->client->request(
            'POST',
            '/api/v1/events/batch',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_' . str_replace('-', '_', strtoupper(ApiKeyAuthenticator::HEADER_NAME)) => $this->apiKey,
            ],
            json_encode(['events' => $events])
        );

        $this->assertResponseStatusCodeSame(202);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('accepted', $response['status']);
        $this->assertSame(100, $response['accepted']);
        $this->assertSame(100, $response['total']);
    }
}
