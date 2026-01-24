<?php

declare(strict_types=1);

namespace App\Messenger\Exception;

/**
 * Exception thrown when a message handler exceeds its configured timeout.
 */
final class MessageTimeoutException extends \RuntimeException
{
    public function __construct(
        public readonly string $messageClass,
        public readonly int $timeoutSeconds,
    ) {
        parent::__construct(sprintf(
            'Message handler for "%s" exceeded timeout of %d seconds',
            $messageClass,
            $timeoutSeconds
        ));
    }
}
