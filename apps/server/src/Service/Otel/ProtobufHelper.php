<?php

declare(strict_types=1);

namespace App\Service\Otel;

use Google\Protobuf\Internal\RepeatedField;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\ArrayValue;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Common\V1\KeyValueList;

/**
 * Helper utilities for working with protobuf OTLP messages.
 */
final class ProtobufHelper
{
    /**
     * Convert binary trace/span ID to lowercase hex string.
     */
    public static function binToHex(string $binary): string
    {
        if ('' === $binary) {
            return '';
        }

        return bin2hex($binary);
    }

    /**
     * Extract value from AnyValue protobuf message.
     */
    public static function extractValue(?AnyValue $anyValue): mixed
    {
        if (null === $anyValue) {
            return null;
        }

        return match (true) {
            $anyValue->hasStringValue() => $anyValue->getStringValue(),
            $anyValue->hasBoolValue() => $anyValue->getBoolValue(),
            $anyValue->hasIntValue() => (int) $anyValue->getIntValue(),
            $anyValue->hasDoubleValue() => $anyValue->getDoubleValue(),
            $anyValue->hasArrayValue() => self::extractArrayValue($anyValue->getArrayValue()),
            $anyValue->hasKvlistValue() => self::extractKvListValue($anyValue->getKvlistValue()),
            $anyValue->hasBytesValue() => base64_encode($anyValue->getBytesValue()),
            default => null,
        };
    }

    /**
     * Convert RepeatedField<KeyValue> to associative array.
     *
     * @param RepeatedField<KeyValue> $attributes
     *
     * @return array<string, mixed>
     */
    public static function attributesToArray(RepeatedField $attributes): array
    {
        $result = [];
        foreach ($attributes as $kv) {
            /* @var KeyValue $kv */
            $result[$kv->getKey()] = self::extractValue($kv->getValue());
        }

        return $result;
    }

    /**
     * Get attribute value by key from RepeatedField<KeyValue>.
     *
     * @param RepeatedField<KeyValue> $attributes
     */
    public static function getAttribute(RepeatedField $attributes, string $key): mixed
    {
        foreach ($attributes as $kv) {
            /** @var KeyValue $kv */
            if ($kv->getKey() === $key) {
                return self::extractValue($kv->getValue());
            }
        }

        return null;
    }

    /**
     * Extract array value.
     *
     * @return array<int, mixed>
     */
    private static function extractArrayValue(?ArrayValue $arrayValue): array
    {
        if (null === $arrayValue) {
            return [];
        }

        $result = [];
        foreach ($arrayValue->getValues() as $value) {
            /* @var AnyValue $value */
            $result[] = self::extractValue($value);
        }

        return $result;
    }

    /**
     * Extract key-value list.
     *
     * @return array<string, mixed>
     */
    private static function extractKvListValue(?KeyValueList $kvList): array
    {
        if (null === $kvList) {
            return [];
        }

        return self::attributesToArray($kvList->getValues());
    }
}
