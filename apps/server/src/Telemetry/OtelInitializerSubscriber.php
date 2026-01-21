<?php

declare(strict_types=1);

namespace App\Telemetry;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Initializes OpenTelemetry SDK early in the request lifecycle.
 *
 * Uses a very high priority (4096) to ensure OTEL is initialized before
 * any auto-instrumentation hooks create spans.
 */
final class OtelInitializerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly OtelInitializer $initializer,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Very high priority to run before instrumentation
            KernelEvents::REQUEST => ['onKernelRequest', 4096],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->initializer->initialize();
    }
}
