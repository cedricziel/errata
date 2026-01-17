<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Telemetry\TracerFactory;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Automatically traces HTTP requests.
 *
 * Creates a root span for each incoming HTTP request and records
 * response status, exceptions, and timing information.
 */
final class TracingSubscriber implements EventSubscriberInterface
{
    /**
     * Routes to exclude from tracing to prevent infinite loops.
     * These are the OTEL ingestion endpoints.
     */
    private const EXCLUDED_ROUTES = [
        'otel_traces',
        'otel_logs',
        'otel_metrics',
    ];

    private const REQUEST_SPAN_KEY = '_otel_span';
    private const REQUEST_SCOPE_KEY = '_otel_scope';

    public function __construct(
        private readonly TracerFactory $tracerFactory,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 255],
            KernelEvents::RESPONSE => ['onKernelResponse', -255],
            KernelEvents::EXCEPTION => ['onKernelException', 0],
            KernelEvents::TERMINATE => ['onKernelTerminate', -255],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->tracerFactory->isEnabled()) {
            return;
        }

        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        // Skip tracing for OTEL ingestion endpoints to prevent infinite loops
        if (\in_array($route, self::EXCLUDED_ROUTES, true)) {
            return;
        }

        $tracer = $this->tracerFactory->createTracer('http');
        $method = $request->getMethod();
        $path = $request->getPathInfo();

        $span = $tracer->spanBuilder("http.server {$method} {$path}")
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.request.method', $method)
            ->setAttribute('url.path', $path)
            ->setAttribute('url.scheme', $request->getScheme())
            ->setAttribute('server.address', $request->getHost())
            ->setAttribute('server.port', $request->getPort())
            ->setAttribute('user_agent.original', $request->headers->get('User-Agent', ''))
            ->startSpan();

        if (null !== $route) {
            $span->setAttribute('http.route', $route);
        }

        $scope = $span->activate();

        $request->attributes->set(self::REQUEST_SPAN_KEY, $span);
        $request->attributes->set(self::REQUEST_SCOPE_KEY, $scope);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $span = $request->attributes->get(self::REQUEST_SPAN_KEY);

        if (!$span instanceof SpanInterface) {
            return;
        }

        $response = $event->getResponse();
        $statusCode = $response->getStatusCode();

        $span->setAttribute('http.response.status_code', $statusCode);

        if ($statusCode >= 400) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        } else {
            $span->setStatus(StatusCode::STATUS_OK);
        }
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $span = $request->attributes->get(self::REQUEST_SPAN_KEY);

        if (!$span instanceof SpanInterface) {
            return;
        }

        $exception = $event->getThrowable();

        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        $span->recordException($exception);
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();

        $scope = $request->attributes->get(self::REQUEST_SCOPE_KEY);
        $span = $request->attributes->get(self::REQUEST_SPAN_KEY);

        if ($scope instanceof ScopeInterface) {
            $scope->detach();
        }

        if ($span instanceof SpanInterface) {
            $span->end();
        }

        // Clean up request attributes
        $request->attributes->remove(self::REQUEST_SPAN_KEY);
        $request->attributes->remove(self::REQUEST_SCOPE_KEY);
    }
}
