<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Status states for async query execution.
 */
enum QueryStatus: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    /**
     * Check if the query is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED], true);
    }
}
