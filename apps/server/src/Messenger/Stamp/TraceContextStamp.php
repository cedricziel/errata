<?php

declare(strict_types=1);

namespace App\Messenger\Stamp;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries W3C trace context through Symfony Messenger queue.
 */
final class TraceContextStamp implements StampInterface
{
    public function __construct(
        private readonly ?string $traceparent = null,
        private readonly ?string $tracestate = null,
    ) {
    }

    /**
     * Create a stamp from the current OpenTelemetry context.
     */
    public static function fromCurrentContext(): self
    {
        $carrier = [];

        TraceContextPropagator::getInstance()->inject(
            $carrier,
            null,
            Context::getCurrent(),
        );

        return new self(
            traceparent: $carrier['traceparent'] ?? null,
            tracestate: $carrier['tracestate'] ?? null,
        );
    }

    /**
     * Extract the OpenTelemetry context from this stamp.
     */
    public function extractContext(): ContextInterface
    {
        if (null === $this->traceparent) {
            return Context::getCurrent();
        }

        $carrier = ['traceparent' => $this->traceparent];

        if (null !== $this->tracestate) {
            $carrier['tracestate'] = $this->tracestate;
        }

        return TraceContextPropagator::getInstance()->extract(
            $carrier,
        );
    }

    public function getTraceparent(): ?string
    {
        return $this->traceparent;
    }

    public function getTracestate(): ?string
    {
        return $this->tracestate;
    }
}
