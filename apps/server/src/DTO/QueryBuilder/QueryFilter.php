<?php

declare(strict_types=1);

namespace App\DTO\QueryBuilder;

/**
 * Represents a single filter condition for query building.
 */
class QueryFilter
{
    public const OPERATOR_EQ = 'eq';
    public const OPERATOR_NEQ = 'neq';
    public const OPERATOR_CONTAINS = 'contains';
    public const OPERATOR_STARTS_WITH = 'starts_with';
    public const OPERATOR_GT = 'gt';
    public const OPERATOR_GTE = 'gte';
    public const OPERATOR_LT = 'lt';
    public const OPERATOR_LTE = 'lte';
    public const OPERATOR_IN = 'in';

    public function __construct(
        public string $attribute,
        public string $operator,
        public mixed $value,
    ) {
    }

    /**
     * @return array<string>
     */
    public static function getStringOperators(): array
    {
        return [
            self::OPERATOR_EQ,
            self::OPERATOR_NEQ,
            self::OPERATOR_CONTAINS,
            self::OPERATOR_STARTS_WITH,
            self::OPERATOR_IN,
        ];
    }

    /**
     * @return array<string>
     */
    public static function getNumericOperators(): array
    {
        return [
            self::OPERATOR_EQ,
            self::OPERATOR_NEQ,
            self::OPERATOR_GT,
            self::OPERATOR_GTE,
            self::OPERATOR_LT,
            self::OPERATOR_LTE,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getOperatorLabels(): array
    {
        return [
            self::OPERATOR_EQ => 'equals',
            self::OPERATOR_NEQ => 'not equals',
            self::OPERATOR_CONTAINS => 'contains',
            self::OPERATOR_STARTS_WITH => 'starts with',
            self::OPERATOR_GT => 'greater than',
            self::OPERATOR_GTE => 'greater or equal',
            self::OPERATOR_LT => 'less than',
            self::OPERATOR_LTE => 'less or equal',
            self::OPERATOR_IN => 'in',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            attribute: $data['attribute'] ?? '',
            operator: $data['operator'] ?? self::OPERATOR_EQ,
            value: $data['value'] ?? '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attribute' => $this->attribute,
            'operator' => $this->operator,
            'value' => $this->value,
        ];
    }
}
