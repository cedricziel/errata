<?php

declare(strict_types=1);

namespace App\DTO\Otel\Common;

/**
 * Represents an OTLP Resource.
 */
class Resource
{
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
     * Get a specific attribute value by key.
     */
    public function getAttribute(string $key): mixed
    {
        foreach ($this->attributes as $attr) {
            if ($attr->key === $key) {
                return $attr->getValue();
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $resource = new self();
        $resource->droppedAttributesCount = (int) ($data['droppedAttributesCount'] ?? 0);

        if (isset($data['attributes']) && is_array($data['attributes'])) {
            foreach ($data['attributes'] as $attr) {
                if (is_array($attr)) {
                    $resource->attributes[] = KeyValue::fromArray($attr);
                }
            }
        }

        return $resource;
    }
}
