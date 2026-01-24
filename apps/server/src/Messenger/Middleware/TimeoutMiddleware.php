<?php

declare(strict_types=1);

namespace App\Messenger\Middleware;

use App\Messenger\Attribute\WithTimeout;
use App\Messenger\Exception\MessageTimeoutException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Middleware that enforces execution timeouts on message handlers.
 *
 * Reads the #[WithTimeout] attribute from message classes and uses
 * pcntl_alarm() to enforce the timeout. Only active when receiving
 * messages (not when dispatching).
 *
 * Requires the pcntl extension to be available.
 */
final class TimeoutMiddleware implements MiddlewareInterface
{
    private static ?string $currentMessageClass = null;
    private static ?int $currentTimeout = null;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Only enforce timeout when receiving messages, not when dispatching
        if (null === $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        // Check if pcntl is available
        if (!function_exists('pcntl_alarm')) {
            $this->logger->debug('TimeoutMiddleware: pcntl extension not available, skipping timeout enforcement');

            return $stack->next()->handle($envelope, $stack);
        }

        $message = $envelope->getMessage();
        $messageClass = $message::class;

        $timeout = $this->getTimeout($message);
        if (null === $timeout) {
            return $stack->next()->handle($envelope, $stack);
        }

        $this->logger->debug('TimeoutMiddleware: setting timeout', [
            'message_class' => $messageClass,
            'timeout_seconds' => $timeout,
        ]);

        // Store for signal handler
        self::$currentMessageClass = $messageClass;
        self::$currentTimeout = $timeout;

        // Enable async signal handling so SIGALRM is delivered promptly
        $previousAsyncSignals = pcntl_async_signals(true);

        // Install signal handler
        pcntl_signal(SIGALRM, [self::class, 'handleTimeout']);

        // Set the alarm
        pcntl_alarm($timeout);

        try {
            $result = $stack->next()->handle($envelope, $stack);

            // Cancel the alarm on success
            pcntl_alarm(0);

            return $result;
        } catch (\Throwable $e) {
            // Cancel the alarm on failure
            pcntl_alarm(0);

            throw $e;
        } finally {
            // Restore default handler and async signals state
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_async_signals($previousAsyncSignals);

            self::$currentMessageClass = null;
            self::$currentTimeout = null;
        }
    }

    /**
     * Signal handler for SIGALRM.
     */
    public static function handleTimeout(int $_signal): void
    {
        throw new MessageTimeoutException(self::$currentMessageClass ?? 'unknown', self::$currentTimeout ?? 0);
    }

    private function getTimeout(object $message): ?int
    {
        $reflection = new \ReflectionClass($message);
        $attributes = $reflection->getAttributes(WithTimeout::class);

        if ([] === $attributes) {
            return null;
        }

        $attribute = $attributes[0]->newInstance();

        return $attribute->seconds;
    }
}
