<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Value object representing a timeframe for filtering data.
 *
 * Used by the global timeframe picker to provide consistent time-based filtering
 * across all pages (Dashboard, Issues, Traces, Logs, Metrics).
 */
readonly class TimeframeDTO
{
    public function __construct(
        public string $preset,
        public string $label,
        public \DateTimeInterface $from,
        public \DateTimeInterface $to,
        public bool $isRelative,
    ) {
    }

    /**
     * Get the timeframe duration in seconds.
     */
    public function getDurationSeconds(): int
    {
        return $this->to->getTimestamp() - $this->from->getTimestamp();
    }

    /**
     * Get the 'from' date formatted for datetime-local input.
     */
    public function getFromFormatted(): string
    {
        return $this->from->format('Y-m-d\TH:i');
    }

    /**
     * Get the 'to' date formatted for datetime-local input.
     */
    public function getToFormatted(): string
    {
        return $this->to->format('Y-m-d\TH:i');
    }
}
