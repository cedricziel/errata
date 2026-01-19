<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Telemetry\TracerFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
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
    /**
     * Routes to exclude from trace flushing to prevent infinite loops.
     * These are the OTEL ingestion endpoints - flushing on these would
     * create new requests to the same endpoints.
     */
    private const EXCLUDED_ROUTES = [
        'otel_traces',
        'otel_logs',
        'otel_metrics',
    ];

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

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$this->tracerFactory->isEnabled()) {
            return;
        }

        // Skip flushing for OTEL ingestion endpoints to prevent infinite loops
        $route = $event->getRequest()->attributes->get('_route');
        if (\in_array($route, self::EXCLUDED_ROUTES, true)) {
            return;
        }

        $this->tracerFactory->forceFlush();
    }
}
