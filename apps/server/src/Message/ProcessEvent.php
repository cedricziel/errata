<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message for processing an incoming event asynchronously.
 */
class ProcessEvent
{
    /**
     * @param array<string, mixed> $eventData   The raw event data
     * @param string               $projectId   The project public ID
     * @param string               $environment The environment (production, staging, development)
     */
    public function __construct(
        public readonly array $eventData,
        public readonly string $projectId,
        public readonly string $environment,
    ) {
    }
}
