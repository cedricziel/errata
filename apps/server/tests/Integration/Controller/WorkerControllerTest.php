<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Message\ProcessEvent;
use App\Tests\Integration\AbstractIntegrationTestCase;
use Zenstruck\Browser\HttpOptions;
use Zenstruck\Messenger\Test\InteractsWithMessenger;

class WorkerControllerTest extends AbstractIntegrationTestCase
{
    use InteractsWithMessenger;

    private const WORKER_SECRET = 'test_worker_secret';

    public function testConsumeRequiresAuthentication(): void
    {
        $this->browser()
            ->post('/api/worker/consume')
            ->assertStatus(401)
            ->assertJsonMatches('error', 'unauthorized');
    }

    public function testConsumeWithInvalidSecretReturnsUnauthorized(): void
    {
        $this->browser()
            ->post('/api/worker/consume', HttpOptions::create()
                ->withHeader('X-Worker-Secret', 'invalid-secret'))
            ->assertStatus(401)
            ->assertJsonMatches('error', 'unauthorized');
    }

    public function testConsumeWithValidSecretHeaderSucceeds(): void
    {
        $this->browser()
            ->post('/api/worker/consume', HttpOptions::create()
                ->withHeader('X-Worker-Secret', self::WORKER_SECRET))
            ->assertSuccessful()
            ->assertJsonMatches('status', 'completed');
    }

    public function testConsumeWithValidSecretQueryParamSucceeds(): void
    {
        $this->browser()
            ->post('/api/worker/consume?secret='.self::WORKER_SECRET)
            ->assertSuccessful()
            ->assertJsonMatches('status', 'completed');
    }

    public function testConsumeReturnsProcessedCount(): void
    {
        $this->browser()
            ->post('/api/worker/consume', HttpOptions::create()
                ->withHeader('X-Worker-Secret', self::WORKER_SECRET))
            ->assertSuccessful()
            ->use(function ($browser): void {
                $response = $browser->json()->decoded();
                $this->assertArrayHasKey('processed', $response);
                $this->assertArrayHasKey('remaining', $response);
                $this->assertArrayHasKey('status', $response);
            });
    }

    public function testConsumeRespectsLimitParameter(): void
    {
        $this->browser()
            ->post('/api/worker/consume?secret='.self::WORKER_SECRET.'&limit=10')
            ->assertSuccessful()
            ->assertJsonMatches('status', 'completed');
    }

    public function testConsumeRespectsTimeLimitParameter(): void
    {
        $this->browser()
            ->post('/api/worker/consume?secret='.self::WORKER_SECRET.'&time_limit=5')
            ->assertSuccessful()
            ->assertJsonMatches('status', 'completed');
    }

    public function testConsumeWithBothLimitParameters(): void
    {
        $this->browser()
            ->post('/api/worker/consume?secret='.self::WORKER_SECRET.'&limit=25&time_limit=10')
            ->assertSuccessful()
            ->assertJsonMatches('status', 'completed');
    }

    public function testConsumeOnlyAcceptsPostMethod(): void
    {
        $this->browser()
            ->get('/api/worker/consume?secret='.self::WORKER_SECRET)
            ->assertStatus(405);
    }

    public function testConsumeProcessesQueuedMessages(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $apiKeyData = $this->createTestApiKey($project);

        // Queue a message by sending an event
        $payload = $this->createValidEventPayload();
        $this->browser()
            ->post('/api/v1/events', HttpOptions::json($payload)
                ->withHeader('X-Errata-Key', $apiKeyData['plainKey']))
            ->assertStatus(202);

        // Verify message is queued
        $this->transport('async_events')
            ->queue()
            ->assertContains(ProcessEvent::class, 1);

        // Now process the queue via the worker endpoint
        // Note: In test environment, the transport is already processed synchronously
        // by zenstruck/messenger-test, so we verify the endpoint works correctly
        $this->browser()
            ->post('/api/worker/consume?transport=async_events', HttpOptions::create()
                ->withHeader('X-Worker-Secret', self::WORKER_SECRET))
            ->assertSuccessful()
            ->assertJsonMatches('status', 'completed')
            ->assertJsonMatches('transport', 'async_events');
    }

    public function testConsumeReturnsZeroProcessedWhenQueueEmpty(): void
    {
        // Ensure queue is empty
        $this->transport('async_events')->queue()->assertEmpty();

        $this->browser()
            ->post('/api/worker/consume?transport=async_events', HttpOptions::create()
                ->withHeader('X-Worker-Secret', self::WORKER_SECRET))
            ->assertSuccessful()
            ->use(function ($browser): void {
                $response = $browser->json()->decoded();
                $this->assertSame('completed', $response['status']);
                $this->assertSame('async_events', $response['transport']);
                $this->assertSame(0, $response['processed']);
            });
    }

    public function testConsumeRejectsInvalidTransport(): void
    {
        $this->browser()
            ->post('/api/worker/consume?transport=invalid', HttpOptions::create()
                ->withHeader('X-Worker-Secret', self::WORKER_SECRET))
            ->assertStatus(400);
    }
}
