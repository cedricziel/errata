<?php

declare(strict_types=1);

namespace App\DTO\Otel\Common;

/**
 * Represents any OTLP attribute value.
 *
 * @see https://opentelemetry.io/docs/specs/otlp/#json-protobuf-encoding
 */
class AnyValue
{
    public ?string $stringValue = null;

    public ?bool $boolValue = null;

    public ?int $intValue = null;

    public ?float $doubleValue = null;

    public ?ArrayValue $arrayValue = null;

    public ?KeyValueList $kvlistValue = null;

    public ?string $bytesValue = null;

    /**
     * Get the actual value from this AnyValue.
     */
    public function getValue(): mixed
    {
        if (null !== $this->stringValue) {
            return $this->stringValue;
        }
        if (null !== $this->boolValue) {
            return $this->boolValue;
        }
        if (null !== $this->intValue) {
            return $this->intValue;
        }
        if (null !== $this->doubleValue) {
            return $this->doubleValue;
        }
        if (null !== $this->arrayValue) {
            return $this->arrayValue->toArray();
        }
        if (null !== $this->kvlistValue) {
            return $this->kvlistValue->toAssociativeArray();
        }
        if (null !== $this->bytesValue) {
            return $this->bytesValue;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $value = new self();

        if (isset($data['stringValue'])) {
            $value->stringValue = (string) $data['stringValue'];
        } elseif (isset($data['boolValue'])) {
            $value->boolValue = (bool) $data['boolValue'];
        } elseif (isset($data['intValue'])) {
            $value->intValue = (int) $data['intValue'];
        } elseif (isset($data['doubleValue'])) {
            $value->doubleValue = (float) $data['doubleValue'];
        } elseif (isset($data['arrayValue'])) {
            $value->arrayValue = ArrayValue::fromArray($data['arrayValue']);
        } elseif (isset($data['kvlistValue'])) {
            $value->kvlistValue = KeyValueList::fromArray($data['kvlistValue']);
        } elseif (isset($data['bytesValue'])) {
            $value->bytesValue = (string) $data['bytesValue'];
        }

        return $value;
    }
}
