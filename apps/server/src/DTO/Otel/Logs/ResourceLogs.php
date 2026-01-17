<?php

declare(strict_types=1);

namespace App\DTO\Otel\Logs;

use App\DTO\Otel\Common\Resource;

/**
 * Represents a collection of scope logs from a single resource.
 */
class ResourceLogs
{
    public ?Resource $resource = null;

    /** @var list<ScopeLogs> */
    public array $scopeLogs = [];

    public ?string $schemaUrl = null;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $resourceLogs = new self();
        $resourceLogs->schemaUrl = isset($data['schemaUrl']) ? (string) $data['schemaUrl'] : null;

        if (isset($data['resource']) && is_array($data['resource'])) {
            $resourceLogs->resource = Resource::fromArray($data['resource']);
        }

        if (isset($data['scopeLogs']) && is_array($data['scopeLogs'])) {
            foreach ($data['scopeLogs'] as $scopeLogs) {
                if (is_array($scopeLogs)) {
                    $resourceLogs->scopeLogs[] = ScopeLogs::fromArray($scopeLogs);
                }
            }
        }

        return $resourceLogs;
    }
}
