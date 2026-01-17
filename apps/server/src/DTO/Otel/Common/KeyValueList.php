<?php

declare(strict_types=1);

namespace App\DTO\Otel\Common;

/**
 * Represents a list of key-value pairs.
 */
class KeyValueList
{
    /** @var list<KeyValue> */
    public array $values = [];

    /**
     * Convert to an associative array.
     *
     * @return array<string, mixed>
     */
    public function toAssociativeArray(): array
    {
        $result = [];
        foreach ($this->values as $kv) {
            $result[$kv->key] = $kv->getValue();
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $list = new self();

        if (isset($data['values']) && is_array($data['values'])) {
            foreach ($data['values'] as $value) {
                if (is_array($value)) {
                    $list->values[] = KeyValue::fromArray($value);
                }
            }
        }

        return $list;
    }
}
