<?php

declare(strict_types=1);

namespace App\DTO\Otel\Trace;

/**
 * Represents an OTLP ExportTraceServiceRequest.
 */
class ExportTraceServiceRequest
{
    /** @var list<ResourceSpans> */
    public array $resourceSpans = [];

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $request = new self();

        if (isset($data['resourceSpans']) && is_array($data['resourceSpans'])) {
            foreach ($data['resourceSpans'] as $resourceSpans) {
                if (is_array($resourceSpans)) {
                    $request->resourceSpans[] = ResourceSpans::fromArray($resourceSpans);
                }
            }
        }

        return $request;
    }
}
