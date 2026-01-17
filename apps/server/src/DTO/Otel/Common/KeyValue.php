<?php

declare(strict_types=1);

namespace App\DTO\Otel\Common;

/**
 * Represents a key-value pair in OTLP format.
 */
class KeyValue
{
    public string $key = '';

    public ?AnyValue $value = null;

    /**
     * Get the actual value from this KeyValue.
     */
    public function getValue(): mixed
    {
        return $this->value?->getValue();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $kv = new self();
        $kv->key = (string) ($data['key'] ?? '');

        if (isset($data['value']) && is_array($data['value'])) {
            $kv->value = AnyValue::fromArray($data['value']);
        }

        return $kv;
    }
}
