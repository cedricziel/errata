<?php

declare(strict_types=1);

namespace App\DTO\Otel\Trace;

use App\DTO\Otel\Common\KeyValue;

/**
 * Represents a link between spans.
 */
class SpanLink
{
    public string $traceId = '';

    public string $spanId = '';

    public string $traceState = '';

    /** @var list<KeyValue> */
    public array $attributes = [];

    public int $droppedAttributesCount = 0;

    /**
     * Get attributes as an associative array.
     *
     * @return array<string, mixed>
     */
    public function getAttributesAsArray(): array
    {
        $result = [];
        foreach ($this->attributes as $attr) {
            $result[$attr->key] = $attr->getValue();
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $link = new self();
        $link->traceId = strtolower((string) ($data['traceId'] ?? ''));
        $link->spanId = strtolower((string) ($data['spanId'] ?? ''));
        $link->traceState = (string) ($data['traceState'] ?? '');
        $link->droppedAttributesCount = (int) ($data['droppedAttributesCount'] ?? 0);

        if (isset($data['attributes']) && is_array($data['attributes'])) {
            foreach ($data['attributes'] as $attr) {
                if (is_array($attr)) {
                    $link->attributes[] = KeyValue::fromArray($attr);
                }
            }
        }

        return $link;
    }
}
