<?php

declare(strict_types=1);

namespace App\DTO\Otel\Trace;

/**
 * Represents the status of a span.
 */
class SpanStatus
{
    public const CODE_UNSET = 0;
    public const CODE_OK = 1;
    public const CODE_ERROR = 2;

    public ?string $message = null;

    public int $code = self::CODE_UNSET;

    /**
     * Get status as a string for storage.
     */
    public function getStatusString(): string
    {
        return match ($this->code) {
            self::CODE_OK => 'ok',
            self::CODE_ERROR => 'error',
            default => 'unset',
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $status = new self();
        $status->message = isset($data['message']) ? (string) $data['message'] : null;
        $status->code = (int) ($data['code'] ?? self::CODE_UNSET);

        return $status;
    }
}
