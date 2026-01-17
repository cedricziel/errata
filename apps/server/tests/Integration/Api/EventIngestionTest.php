<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Security\ApiKeyAuthenticator;
use App\Tests\Integration\AbstractIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Zenstruck\Browser\HttpOptions;

class EventIngestionTest extends AbstractIntegrationTestCase
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

    public function testValidEventReturns202Accepted(): void
    {
        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($this->createValidEventPayload())
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey))
            ->assertStatus(202)
            ->assertJsonMatches('status', 'accepted')
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $this->assertArrayHasKey('message', $response);
            });
    }

    public function testInvalidJsonReturns400(): void
    {
        $this->browser()
            ->post('/api/v1/events', HttpOptions::create()
                ->withHeader('Content-Type', 'application/json')
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey)
                ->withBody('not valid json'))
            ->assertStatus(400)
            ->assertJsonMatches('error', 'bad_request');
    }

    public function testEmptyEventTypeReturns400(): void
    {
        // Note: WideEventPayload::fromArray() sets default event_type='error' when missing,
        // so we test with an explicitly empty string which should fail validation
        $payload = ['event_type' => '', 'message' => 'Test message'];

        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($payload)
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey))
            ->assertStatus(400)
            ->assertJsonMatches('error', 'bad_request')
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $this->assertArrayHasKey('details', $response);
            });
    }

    public function testInvalidEventTypeReturns400(): void
    {
        $payload = $this->createValidEventPayload(['event_type' => 'invalid_type']);

        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($payload)
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey))
            ->assertStatus(400)
            ->assertJsonMatches('error', 'bad_request')
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $this->assertArrayHasKey('details', $response);
            });
    }

    public function testInvalidSeverityReturns400(): void
    {
        $payload = $this->createValidEventPayload(['severity' => 'invalid_severity']);

        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($payload)
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey))
            ->assertStatus(400)
            ->assertJsonMatches('error', 'bad_request')
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $this->assertArrayHasKey('details', $response);
            });
    }

    #[DataProvider('validEventTypesProvider')]
    public function testAllValidEventTypesWork(string $eventType): void
    {
        $payload = $this->createValidEventPayload(['event_type' => $eventType]);

        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($payload)
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey))
            ->assertStatus(202)
            ->assertJsonMatches('status', 'accepted');
    }

    /**
     * @return array<array<string>>
     */
    public static function validEventTypesProvider(): array
    {
        return [
            ['crash'],
            ['error'],
            ['log'],
            ['metric'],
            ['span'],
        ];
    }

    #[DataProvider('validSeverityLevelsProvider')]
    public function testAllValidSeverityLevelsWork(string $severity): void
    {
        $payload = $this->createValidEventPayload(['severity' => $severity]);

        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($payload)
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey))
            ->assertStatus(202)
            ->assertJsonMatches('status', 'accepted');
    }

    /**
     * @return array<array<string>>
     */
    public static function validSeverityLevelsProvider(): array
    {
        return [
            ['trace'],
            ['debug'],
            ['info'],
            ['warning'],
            ['error'],
            ['fatal'],
        ];
    }

    public function testCrashEventWithStackTraceSucceeds(): void
    {
        $payload = $this->createValidEventPayload([
            'event_type' => 'crash',
            'exception_type' => 'NSException',
            'message' => 'Array index out of bounds',
            'stack_trace' => [
                ['function' => 'main', 'file' => 'main.swift', 'line' => 10],
                ['function' => 'viewDidLoad', 'file' => 'ViewController.swift', 'line' => 25],
            ],
        ]);

        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($payload)
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey))
            ->assertStatus(202)
            ->assertJsonMatches('status', 'accepted');
    }

    public function testEventWithFullMetadataSucceeds(): void
    {
        $payload = [
            'event_type' => 'error',
            'severity' => 'error',
            'message' => 'Something went wrong',
            'exception_type' => 'RuntimeError',
            'app_version' => '2.1.0',
            'app_build' => '245',
            'bundle_id' => 'com.example.app',
            'environment' => 'production',
            'device_model' => 'iPhone 15 Pro',
            'device_id' => 'device-uuid-123',
            'os_name' => 'iOS',
            'os_version' => '17.2',
            'locale' => 'en_US',
            'timezone' => 'America/New_York',
            'memory_used' => 512000000,
            'memory_total' => 1024000000,
            'disk_free' => 5000000000,
            'battery_level' => 0.85,
            'user_id' => 'user-123',
            'session_id' => 'session-456',
            'tags' => ['environment' => 'prod', 'version' => '2.1.0'],
            'context' => ['user_action' => 'button_click'],
            'breadcrumbs' => [
                ['type' => 'navigation', 'message' => 'Opened settings'],
                ['type' => 'user', 'message' => 'Clicked save'],
            ],
        ];

        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($payload)
                ->withHeader(ApiKeyAuthenticator::HEADER_NAME, $this->apiKey))
            ->assertStatus(202)
            ->assertJsonMatches('status', 'accepted');
    }
}
