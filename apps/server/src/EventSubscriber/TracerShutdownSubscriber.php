<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Telemetry\TracerFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ensures all pending spans are flushed on kernel terminate.
 *
 * This subscriber runs at a low priority on TERMINATE to ensure
 * the BatchSpanProcessor exports all collected spans before
 * the PHP process ends.
 */
final class TracerShutdownSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TracerFactory $tracerFactory,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['onKernelTerminate', -1024],
        ];
    }

    public function onKernelTerminate(): void
    {
        if ($this->tracerFactory->isEnabled()) {
            $this->tracerFactory->forceFlush();
        }
    }
}
