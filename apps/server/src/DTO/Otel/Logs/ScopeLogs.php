<?php

declare(strict_types=1);

namespace App\DTO\Otel\Logs;

use App\DTO\Otel\Common\InstrumentationScope;

/**
 * Represents a collection of log records from a single instrumentation scope.
 */
class ScopeLogs
{
    public ?InstrumentationScope $scope = null;

    /** @var list<LogRecord> */
    public array $logRecords = [];

    public ?string $schemaUrl = null;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $scopeLogs = new self();
        $scopeLogs->schemaUrl = isset($data['schemaUrl']) ? (string) $data['schemaUrl'] : null;

        if (isset($data['scope']) && is_array($data['scope'])) {
            $scopeLogs->scope = InstrumentationScope::fromArray($data['scope']);
        }

        if (isset($data['logRecords']) && is_array($data['logRecords'])) {
            foreach ($data['logRecords'] as $log) {
                if (is_array($log)) {
                    $scopeLogs->logRecords[] = LogRecord::fromArray($log);
                }
            }
        }

        return $scopeLogs;
    }
}
