<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Security\ApiKeyAuthenticator;
use App\Tests\Integration\AbstractIntegrationTestCase;
use Zenstruck\Browser\HttpOptions;

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

        $this->browser()
            ->post('/api/v1/events/batch', HttpOptions::json(['events' => $events])
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey))
            ->assertStatus(202)
            ->assertJsonMatches('status', 'accepted')
            ->assertJsonMatches('accepted', 3)
            ->assertJsonMatches('total', 3)
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $this->assertArrayNotHasKey('errors', $response);
            });
    }

    public function testDirectArrayFormatWorks(): void
    {
        $events = [
            $this->createValidEventPayload(['message' => 'Error 1']),
            $this->createValidEventPayload(['message' => 'Error 2']),
        ];

        $this->browser()
            ->post('/api/v1/events/batch', HttpOptions::json($events)
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey))
            ->assertStatus(202)
            ->assertJsonMatches('status', 'accepted')
            ->assertJsonMatches('accepted', 2)
            ->assertJsonMatches('total', 2);
    }

    public function testBatchExceeding100EventsReturns400(): void
    {
        $events = [];
        for ($i = 0; $i < 101; ++$i) {
            $events[] = $this->createValidEventPayload(['message' => "Error $i"]);
        }

        $this->browser()
            ->post('/api/v1/events/batch', HttpOptions::json(['events' => $events])
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey))
            ->assertStatus(400)
            ->assertJsonMatches('error', 'bad_request')
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $this->assertStringContainsString('100', $response['message']);
            });
    }

    public function testPartialValidationErrorsValidEventsAccepted(): void
    {
        $events = [
            $this->createValidEventPayload(['message' => 'Valid error 1']),
            ['event_type' => 'invalid_type', 'message' => 'Invalid event'],
            $this->createValidEventPayload(['message' => 'Valid error 2']),
        ];

        $this->browser()
            ->post('/api/v1/events/batch', HttpOptions::json(['events' => $events])
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey))
            ->assertStatus(202)
            ->assertJsonMatches('status', 'accepted')
            ->assertJsonMatches('accepted', 2)
            ->assertJsonMatches('total', 3)
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $this->assertArrayHasKey('errors', $response);
                $this->assertArrayHasKey(1, $response['errors']);
            });
    }

    public function testEmptyBatchReturns400(): void
    {
        $this->browser()
            ->post('/api/v1/events/batch', HttpOptions::json(['events' => []])
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey))
            ->assertStatus(400)
            ->assertJsonMatches('error', 'bad_request');
    }

    public function testInvalidJsonReturns400(): void
    {
        $this->browser()
            ->post('/api/v1/events/batch', HttpOptions::create()
                ->withHeader('Content-Type', 'application/json')
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey)
                ->withBody('not valid json'))
            ->assertStatus(400)
            ->assertJsonMatches('error', 'bad_request');
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

        $this->browser()
            ->post('/api/v1/events/batch', HttpOptions::json(['events' => $events])
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey))
            ->assertStatus(202)
            ->assertJsonMatches('status', 'accepted')
            ->assertJsonMatches('accepted', 5)
            ->assertJsonMatches('total', 5);
    }

    public function testExactly100EventsSucceeds(): void
    {
        $events = [];
        for ($i = 0; $i < 100; ++$i) {
            $events[] = $this->createValidEventPayload(['message' => "Error $i"]);
        }

        $this->browser()
            ->post('/api/v1/events/batch', HttpOptions::json(['events' => $events])
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey))
            ->assertStatus(202)
            ->assertJsonMatches('status', 'accepted')
            ->assertJsonMatches('accepted', 100)
            ->assertJsonMatches('total', 100);
    }
}
