<?php

declare(strict_types=1);

namespace App\Messenger\Middleware;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Middleware that logs debug information for message dispatch and consumption.
 *
 * Provides visibility into:
 * - Message dispatch (when a message is sent to a transport)
 * - Message reception (when a message is received from a transport)
 * - Processing duration
 * - Transport routing
 */
final class DebugLoggingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        $messageClass = $message::class;
        $shortClassName = substr(strrchr($messageClass, '\\') ?: $messageClass, 1);

        $receivedStamp = $envelope->last(ReceivedStamp::class);
        $isReceiving = null !== $receivedStamp;

        if ($isReceiving) {
            $this->logger->debug('Messenger: receiving message from transport', [
                'message_class' => $messageClass,
                'short_class' => $shortClassName,
                'transport' => $receivedStamp->getTransportName(),
            ]);
        } else {
            $this->logger->debug('Messenger: dispatching message', [
                'message_class' => $messageClass,
                'short_class' => $shortClassName,
            ]);
        }

        $startTime = microtime(true);

        try {
            $result = $stack->next()->handle($envelope, $stack);

            $duration = (microtime(true) - $startTime) * 1000;

            $sentStamp = $result->last(SentStamp::class);
            $transportIdStamp = $result->last(TransportMessageIdStamp::class);

            $logContext = [
                'message_class' => $messageClass,
                'short_class' => $shortClassName,
                'duration_ms' => round($duration, 2),
            ];

            if (null !== $sentStamp) {
                $logContext['sent_to'] = $sentStamp->getSenderAlias();
            }

            if (null !== $transportIdStamp) {
                $logContext['transport_id'] = $transportIdStamp->getId();
            }

            $this->logger->debug('Messenger: message processed', $logContext);

            return $result;
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logger->error('Messenger: message failed', [
                'message_class' => $messageClass,
                'short_class' => $shortClassName,
                'duration_ms' => round($duration, 2),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
