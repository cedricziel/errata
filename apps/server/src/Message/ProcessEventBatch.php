<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message for processing a batch of events asynchronously.
 *
 * Enables efficient parquet writes by grouping multiple events into a single message,
 * which results in fewer but larger parquet files.
 */
final class ProcessEventBatch
{
    /**
     * @param array<array<string, mixed>> $events      The raw event data array
     * @param string                      $projectId   The project public ID
     * @param string                      $environment The environment (production, staging, development)
     */
    public function __construct(
        public readonly array $events,
        public readonly string $projectId,
        public readonly string $environment,
    ) {
    }
}
