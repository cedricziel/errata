<?php

declare(strict_types=1);

namespace App\Messenger\Attribute;

/**
 * Attribute to specify maximum execution time for a message handler.
 *
 * When applied to a message class, the TimeoutMiddleware will enforce
 * this timeout using pcntl_alarm(). If the handler exceeds the timeout,
 * a MessageTimeoutException is thrown.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class WithTimeout
{
    /**
     * @param int $seconds Maximum execution time in seconds
     */
    public function __construct(
        public readonly int $seconds,
    ) {
    }
}
