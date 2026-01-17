<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\TimeframeService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Twig extension that provides timeframe data as global variables.
 *
 * These variables are used to display the timeframe picker dropdown
 * in the navigation bar.
 */
class TimeframeExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly TimeframeService $timeframeService,
        private readonly Security $security,
    ) {
    }

    public function getGlobals(): array
    {
        // Only provide timeframe data for authenticated users
        if (null === $this->security->getUser()) {
            return [
                'current_timeframe' => null,
                'timeframe_presets' => [],
            ];
        }

        return [
            'current_timeframe' => $this->timeframeService->getCurrentTimeframe(),
            'timeframe_presets' => $this->timeframeService->getPresets(),
        ];
    }
}
