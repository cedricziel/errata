<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\TimeframeDTO;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for managing the global timeframe picker state.
 *
 * Similar to OrganizationSwitcher, this stores the user's selected timeframe
 * in the session and resolves it with optional URL parameter overrides.
 */
class TimeframeService
{
    private const SESSION_KEY = 'timeframe_preset';
    private const SESSION_CUSTOM_FROM = 'timeframe_custom_from';
    private const SESSION_CUSTOM_TO = 'timeframe_custom_to';
    private const DEFAULT_PRESET = 'last_1h';

    /**
     * Available presets with their labels and date modifiers.
     *
     * @var array<string, array{label: string, modifier: string}>
     */
    public const PRESETS = [
        'last_15m' => ['label' => 'Last 15 minutes', 'modifier' => '-15 minutes'],
        'last_1h' => ['label' => 'Last 1 hour', 'modifier' => '-1 hour'],
        'last_6h' => ['label' => 'Last 6 hours', 'modifier' => '-6 hours'],
        'last_24h' => ['label' => 'Last 24 hours', 'modifier' => '-24 hours'],
        'last_7d' => ['label' => 'Last 7 days', 'modifier' => '-7 days'],
        'last_30d' => ['label' => 'Last 30 days', 'modifier' => '-30 days'],
    ];

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Get the current timeframe, resolving from URL params or session.
     *
     * URL parameters (from/to) take precedence over session-stored values.
     */
    public function resolveTimeframe(?Request $request = null): TimeframeDTO
    {
        $request ??= $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return $this->buildFromPreset(self::DEFAULT_PRESET);
        }

        // Check for URL parameter overrides
        $urlFrom = $request->query->get('from');
        $urlTo = $request->query->get('to');

        if (null !== $urlFrom || null !== $urlTo) {
            return $this->buildFromUrlParams($urlFrom, $urlTo);
        }

        // Fall back to session
        return $this->getCurrentTimeframe();
    }

    /**
     * Get the current timeframe from session.
     */
    public function getCurrentTimeframe(): TimeframeDTO
    {
        $session = $this->requestStack->getSession();
        $preset = $session->get(self::SESSION_KEY, self::DEFAULT_PRESET);

        if ('custom' === $preset) {
            $customFrom = $session->get(self::SESSION_CUSTOM_FROM);
            $customTo = $session->get(self::SESSION_CUSTOM_TO);

            if (null !== $customFrom && null !== $customTo) {
                try {
                    return new TimeframeDTO(
                        preset: 'custom',
                        label: 'Custom Range',
                        from: new \DateTimeImmutable($customFrom),
                        to: new \DateTimeImmutable($customTo),
                        isRelative: false,
                    );
                } catch (\Exception) {
                    // Fall back to default if parsing fails
                }
            }
        }

        if (isset(self::PRESETS[$preset])) {
            return $this->buildFromPreset($preset);
        }

        return $this->buildFromPreset(self::DEFAULT_PRESET);
    }

    /**
     * Set the current timeframe to a preset.
     */
    public function setPreset(string $preset): void
    {
        if (!isset(self::PRESETS[$preset])) {
            throw new \InvalidArgumentException(\sprintf('Invalid preset: %s', $preset));
        }

        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_KEY, $preset);
        $session->remove(self::SESSION_CUSTOM_FROM);
        $session->remove(self::SESSION_CUSTOM_TO);
    }

    /**
     * Set a custom time range.
     */
    public function setCustomRange(\DateTimeInterface $from, \DateTimeInterface $to): void
    {
        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_KEY, 'custom');
        $session->set(self::SESSION_CUSTOM_FROM, $from->format('Y-m-d\TH:i:s'));
        $session->set(self::SESSION_CUSTOM_TO, $to->format('Y-m-d\TH:i:s'));
    }

    /**
     * Get all available presets for display.
     *
     * @return array<string, string> preset key => label
     */
    public function getPresets(): array
    {
        $result = [];
        foreach (self::PRESETS as $key => $config) {
            $result[$key] = $config['label'];
        }

        return $result;
    }

    /**
     * Build a TimeframeDTO from a preset.
     */
    private function buildFromPreset(string $preset): TimeframeDTO
    {
        $config = self::PRESETS[$preset] ?? self::PRESETS[self::DEFAULT_PRESET];
        $now = new \DateTimeImmutable();

        return new TimeframeDTO(
            preset: $preset,
            label: $config['label'],
            from: $now->modify($config['modifier']),
            to: $now,
            isRelative: true,
        );
    }

    /**
     * Build a TimeframeDTO from URL parameters.
     */
    private function buildFromUrlParams(?string $from, ?string $to): TimeframeDTO
    {
        $now = new \DateTimeImmutable();

        try {
            $fromDate = null !== $from && '' !== $from ? new \DateTimeImmutable($from) : $now->modify('-1 hour');
            $toDate = null !== $to && '' !== $to ? new \DateTimeImmutable($to) : $now;

            return new TimeframeDTO(
                preset: 'custom',
                label: 'Custom Range',
                from: $fromDate,
                to: $toDate,
                isRelative: false,
            );
        } catch (\Exception) {
            return $this->buildFromPreset(self::DEFAULT_PRESET);
        }
    }
}
