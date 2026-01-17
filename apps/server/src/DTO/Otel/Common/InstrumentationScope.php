<?php

declare(strict_types=1);

namespace App\DTO\Otel\Common;

/**
 * Represents an instrumentation scope (formerly InstrumentationLibrary).
 */
class InstrumentationScope
{
    public string $name = '';

    public ?string $version = null;

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
        $scope = new self();
        $scope->name = (string) ($data['name'] ?? '');
        $scope->version = isset($data['version']) ? (string) $data['version'] : null;
        $scope->droppedAttributesCount = (int) ($data['droppedAttributesCount'] ?? 0);

        if (isset($data['attributes']) && is_array($data['attributes'])) {
            foreach ($data['attributes'] as $attr) {
                if (is_array($attr)) {
                    $scope->attributes[] = KeyValue::fromArray($attr);
                }
            }
        }

        return $scope;
    }
}
