<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DTO\TimeframeDTO;
use App\Service\TimeframeService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class TimeframeServiceTest extends TestCase
{
    private RequestStack $requestStack;
    private Session $session;
    private TimeframeService $service;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->requestStack = new RequestStack();

        $request = new Request();
        $request->setSession($this->session);
        $this->requestStack->push($request);

        $this->service = new TimeframeService($this->requestStack);
    }

    public function testGetPresetsReturnsAllPresets(): void
    {
        $presets = $this->service->getPresets();

        $this->assertArrayHasKey('last_15m', $presets);
        $this->assertArrayHasKey('last_1h', $presets);
        $this->assertArrayHasKey('last_6h', $presets);
        $this->assertArrayHasKey('last_24h', $presets);
        $this->assertArrayHasKey('last_7d', $presets);
        $this->assertArrayHasKey('last_30d', $presets);

        $this->assertSame('Last 15 minutes', $presets['last_15m']);
        $this->assertSame('Last 1 hour', $presets['last_1h']);
        $this->assertSame('Last 30 days', $presets['last_30d']);
    }

    public function testGetCurrentTimeframeReturnsDefaultWhenNotSet(): void
    {
        $timeframe = $this->service->getCurrentTimeframe();

        $this->assertSame('last_1h', $timeframe->preset);
        $this->assertSame('Last 1 hour', $timeframe->label);
        $this->assertTrue($timeframe->isRelative);
    }

    public function testSetPresetChangesCurrentTimeframe(): void
    {
        $this->service->setPreset('last_24h');

        $timeframe = $this->service->getCurrentTimeframe();

        $this->assertSame('last_24h', $timeframe->preset);
        $this->assertSame('Last 24 hours', $timeframe->label);
        $this->assertTrue($timeframe->isRelative);
    }

    public function testSetPresetThrowsExceptionForInvalidPreset(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid preset: invalid_preset');

        $this->service->setPreset('invalid_preset');
    }

    public function testSetCustomRangeSetsCustomTimeframe(): void
    {
        $from = new \DateTimeImmutable('2024-01-01 00:00:00');
        $to = new \DateTimeImmutable('2024-01-15 23:59:59');

        $this->service->setCustomRange($from, $to);

        $timeframe = $this->service->getCurrentTimeframe();

        $this->assertSame('custom', $timeframe->preset);
        $this->assertSame('Custom Range', $timeframe->label);
        $this->assertFalse($timeframe->isRelative);
        $this->assertSame('2024-01-01', $timeframe->from->format('Y-m-d'));
        $this->assertSame('2024-01-15', $timeframe->to->format('Y-m-d'));
    }

    public function testResolveTimeframeUsesUrlParamsWhenProvided(): void
    {
        $request = new Request(['from' => '2024-01-01T10:00', 'to' => '2024-01-01T18:00']);
        $request->setSession($this->session);
        $this->requestStack->pop();
        $this->requestStack->push($request);

        $timeframe = $this->service->resolveTimeframe($request);

        $this->assertSame('custom', $timeframe->preset);
        $this->assertFalse($timeframe->isRelative);
        $this->assertSame('2024-01-01', $timeframe->from->format('Y-m-d'));
        $this->assertSame('10:00', $timeframe->from->format('H:i'));
    }

    public function testResolveTimeframeFallsBackToSessionWhenNoUrlParams(): void
    {
        $this->service->setPreset('last_7d');

        $request = new Request();
        $request->setSession($this->session);

        $timeframe = $this->service->resolveTimeframe($request);

        $this->assertSame('last_7d', $timeframe->preset);
        $this->assertTrue($timeframe->isRelative);
    }

    public function testResolveTimeframeHandlesPartialUrlParams(): void
    {
        $request = new Request(['from' => '2024-01-01T10:00']);
        $request->setSession($this->session);
        $this->requestStack->pop();
        $this->requestStack->push($request);

        $timeframe = $this->service->resolveTimeframe($request);

        $this->assertSame('custom', $timeframe->preset);
        $this->assertFalse($timeframe->isRelative);
    }

    public function testResolveTimeframeHandlesInvalidUrlParams(): void
    {
        $request = new Request(['from' => 'invalid-date', 'to' => 'also-invalid']);
        $request->setSession($this->session);
        $this->requestStack->pop();
        $this->requestStack->push($request);

        $timeframe = $this->service->resolveTimeframe($request);

        // Should fall back to default
        $this->assertSame('last_1h', $timeframe->preset);
        $this->assertTrue($timeframe->isRelative);
    }

    public function testTimeframeDTOCalculatesDurationCorrectly(): void
    {
        $from = new \DateTimeImmutable('2024-01-01 00:00:00');
        $to = new \DateTimeImmutable('2024-01-02 00:00:00');

        $timeframe = new TimeframeDTO(
            preset: 'custom',
            label: 'Custom',
            from: $from,
            to: $to,
            isRelative: false,
        );

        $this->assertSame(86400, $timeframe->getDurationSeconds()); // 24 hours in seconds
    }

    public function testTimeframeDTOFormattedDates(): void
    {
        $from = new \DateTimeImmutable('2024-01-15 14:30:00');
        $to = new \DateTimeImmutable('2024-01-20 18:45:00');

        $timeframe = new TimeframeDTO(
            preset: 'custom',
            label: 'Custom',
            from: $from,
            to: $to,
            isRelative: false,
        );

        $this->assertSame('2024-01-15T14:30', $timeframe->getFromFormatted());
        $this->assertSame('2024-01-20T18:45', $timeframe->getToFormatted());
    }

    public function testRelativePresetCalculatesFromDateCorrectly(): void
    {
        $this->service->setPreset('last_1h');
        $timeframe = $this->service->getCurrentTimeframe();

        $now = new \DateTimeImmutable();
        $oneHourAgo = $now->modify('-1 hour');

        // Allow 2 seconds tolerance for test execution time
        $this->assertEqualsWithDelta(
            $oneHourAgo->getTimestamp(),
            $timeframe->from->getTimestamp(),
            2,
        );
        $this->assertEqualsWithDelta(
            $now->getTimestamp(),
            $timeframe->to->getTimestamp(),
            2,
        );
    }

    public function testSetPresetClearsCustomRange(): void
    {
        // First set a custom range
        $from = new \DateTimeImmutable('2024-01-01 00:00:00');
        $to = new \DateTimeImmutable('2024-01-15 23:59:59');
        $this->service->setCustomRange($from, $to);

        // Then set a preset
        $this->service->setPreset('last_6h');

        $timeframe = $this->service->getCurrentTimeframe();

        $this->assertSame('last_6h', $timeframe->preset);
        $this->assertTrue($timeframe->isRelative);
    }
}
