<?php

declare(strict_types=1);

namespace App\DTO\Otel\Logs;

/**
 * Represents an OTLP ExportLogsServiceRequest.
 */
class ExportLogsServiceRequest
{
    /** @var list<ResourceLogs> */
    public array $resourceLogs = [];

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $request = new self();

        if (isset($data['resourceLogs']) && is_array($data['resourceLogs'])) {
            foreach ($data['resourceLogs'] as $resourceLogs) {
                if (is_array($resourceLogs)) {
                    $request->resourceLogs[] = ResourceLogs::fromArray($resourceLogs);
                }
            }
        }

        return $request;
    }
}
