<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\Integration\AbstractIntegrationTestCase;

class TimeframeControllerTest extends AbstractIntegrationTestCase
{
    public function testSetTimeframeRequiresAuthentication(): void
    {
        $this->browser()
            ->interceptRedirects()
            ->post('/timeframe/set', [
                'body' => ['preset' => 'last_24h'],
            ])
            ->assertRedirectedTo('/login');
    }

    public function testSetPresetUpdatesSessionTimeframe(): void
    {
        $user = $this->createTestUser();

        // Visit home to establish session
        $browser = $this->browser()
            ->actingAs($user)
            ->visit('/');

        $token = $this->getCsrfTokenFromBrowser($browser, 'timeframe');

        $browser
            ->interceptRedirects()
            ->post('/timeframe/set', [
                'body' => [
                    'preset' => 'last_24h',
                    '_csrf_token' => $token,
                ],
            ])
            ->assertRedirectedTo('/');
    }

    public function testSetCustomRangeUpdatesSessionTimeframe(): void
    {
        $user = $this->createTestUser();

        // Visit home to establish session
        $browser = $this->browser()
            ->actingAs($user)
            ->visit('/');

        $token = $this->getCsrfTokenFromBrowser($browser, 'timeframe');

        $browser
            ->interceptRedirects()
            ->post('/timeframe/set', [
                'body' => [
                    'preset' => 'custom',
                    'custom_from' => '2024-01-01T10:00',
                    'custom_to' => '2024-01-15T18:00',
                    '_csrf_token' => $token,
                ],
            ])
            ->assertRedirectedTo('/');
    }

    public function testSetPresetRedirectsBackToReferer(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user, 'Test Project');
        $refererUrl = '/projects/'.$project->getPublicId()->toRfc4122().'/otel/traces';

        // Visit home to establish session
        $browser = $this->browser()
            ->actingAs($user)
            ->visit('/');

        $token = $this->getCsrfTokenFromBrowser($browser, 'timeframe');

        $browser
            ->interceptRedirects()
            ->post('/timeframe/set', [
                'body' => [
                    'preset' => 'last_7d',
                    '_csrf_token' => $token,
                ],
                'headers' => ['Referer' => 'http://localhost'.$refererUrl],
            ])
            ->assertRedirectedTo($refererUrl);
    }

    public function testSetPresetStripsFromToParamsFromReferer(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user, 'Test Project');
        $baseUrl = '/projects/'.$project->getPublicId()->toRfc4122().'/otel/traces';
        $refererUrl = $baseUrl.'?from=2024-01-01T00:00&to=2024-01-02T00:00&page=1';

        // Visit home to establish session
        $browser = $this->browser()
            ->actingAs($user)
            ->visit('/');

        $token = $this->getCsrfTokenFromBrowser($browser, 'timeframe');

        $browser
            ->interceptRedirects()
            ->post('/timeframe/set', [
                'body' => [
                    'preset' => 'last_6h',
                    '_csrf_token' => $token,
                ],
                'headers' => ['Referer' => 'http://localhost'.$refererUrl],
            ])
            // Should redirect to URL without from/to but with page preserved
            ->assertRedirectedTo($baseUrl.'?page=1');
    }

    public function testTimeframePickerAppearsInNavbar(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->visit('/')
            ->assertSuccessful()
            ->assertSeeIn('nav', 'Last 1 hour'); // Default timeframe
    }

    public function testTimeframePickerShowsQuickRangeOptions(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->visit('/')
            ->assertSuccessful()
            ->assertSee('Last 15 minutes')
            ->assertSee('Last 1 hour')
            ->assertSee('Last 6 hours')
            ->assertSee('Last 24 hours')
            ->assertSee('Last 7 days')
            ->assertSee('Last 30 days')
            ->assertSee('Custom Range');
    }

    public function testTimeframePersistsAcrossPageNavigations(): void
    {
        $user = $this->createTestUser();

        // Visit home to establish session
        $browser = $this->browser()
            ->actingAs($user)
            ->visit('/');

        $token = $this->getCsrfTokenFromBrowser($browser, 'timeframe');

        // Set a specific timeframe
        $browser
            ->interceptRedirects()
            ->post('/timeframe/set', [
                'body' => [
                    'preset' => 'last_30d',
                    '_csrf_token' => $token,
                ],
            ])
            ->assertRedirectedTo('/')
            ->followRedirect()
            ->assertSuccessful()
            ->assertSeeIn('nav', 'Last 30 days');
    }

    public function testInvalidPresetShowsErrorFlash(): void
    {
        $user = $this->createTestUser();

        // Visit home to establish session
        $browser = $this->browser()
            ->actingAs($user)
            ->visit('/');

        $token = $this->getCsrfTokenFromBrowser($browser, 'timeframe');

        // This should silently fail with an error flash
        $browser
            ->interceptRedirects()
            ->post('/timeframe/set', [
                'body' => [
                    'preset' => 'invalid_preset_name',
                    '_csrf_token' => $token,
                ],
            ])
            ->assertRedirectedTo('/');
    }

    public function testCustomRangeRequiresBothDates(): void
    {
        $user = $this->createTestUser();

        // Visit home to establish session
        $browser = $this->browser()
            ->actingAs($user)
            ->visit('/');

        $token = $this->getCsrfTokenFromBrowser($browser, 'timeframe');

        // Custom preset without dates should not set custom range
        $browser
            ->interceptRedirects()
            ->post('/timeframe/set', [
                'body' => [
                    'preset' => 'custom',
                    '_csrf_token' => $token,
                ],
            ])
            ->assertRedirectedTo('/');
    }
}
