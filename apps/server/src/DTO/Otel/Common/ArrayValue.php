<?php

declare(strict_types=1);

namespace App\DTO\Otel\Common;

/**
 * Represents an array of OTLP values.
 */
class ArrayValue
{
    /** @var list<AnyValue> */
    public array $values = [];

    /**
     * Convert to a PHP array of values.
     *
     * @return list<mixed>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (AnyValue $v) => $v->getValue(),
            $this->values
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $arrayValue = new self();

        if (isset($data['values']) && is_array($data['values'])) {
            foreach ($data['values'] as $value) {
                if (is_array($value)) {
                    $arrayValue->values[] = AnyValue::fromArray($value);
                }
            }
        }

        return $arrayValue;
    }
}
