<?php

declare(strict_types=1);

namespace App\Messenger\Middleware;

use App\Messenger\Stamp\TraceContextStamp;
use App\Service\Telemetry\TracerFactory;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Middleware that propagates OpenTelemetry trace context through Messenger.
 *
 * On send: Injects current trace context into the envelope as a stamp.
 * On receive: Extracts trace context and creates a child span for message processing.
 */
final class TraceContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TracerFactory $tracerFactory,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$this->tracerFactory->isEnabled()) {
            return $stack->next()->handle($envelope, $stack);
        }

        // Check if this is a received message (from queue) or being sent
        $receivedStamp = $envelope->last(ReceivedStamp::class);

        if (null !== $receivedStamp) {
            return $this->handleReceived($envelope, $stack);
        }

        return $this->handleSend($envelope, $stack);
    }

    /**
     * When sending a message, inject the current trace context.
     */
    private function handleSend(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Only add stamp if one doesn't already exist
        if (null === $envelope->last(TraceContextStamp::class)) {
            $envelope = $envelope->with(TraceContextStamp::fromCurrentContext());
        }

        return $stack->next()->handle($envelope, $stack);
    }

    /**
     * When receiving a message, extract trace context and create a processing span.
     */
    private function handleReceived(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(TraceContextStamp::class);

        // Extract parent context from stamp or use current
        $parentContext = $stamp instanceof TraceContextStamp
            ? $stamp->extractContext()
            : Context::getCurrent();

        $tracer = $this->tracerFactory->createTracer('messenger');
        $messageClass = $envelope->getMessage()::class;
        $shortClassName = substr(strrchr($messageClass, '\\') ?: $messageClass, 1);

        $span = $tracer->spanBuilder("messenger.process {$shortClassName}")
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setParent($parentContext)
            ->setAttribute('messaging.system', 'symfony_messenger')
            ->setAttribute('messaging.operation', 'process')
            ->setAttribute('messaging.message.class', $messageClass)
            ->startSpan();

        $scope = $span->activate();

        try {
            $result = $stack->next()->handle($envelope, $stack);
            $span->setStatus(StatusCode::STATUS_OK);

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->recordException($e);

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
