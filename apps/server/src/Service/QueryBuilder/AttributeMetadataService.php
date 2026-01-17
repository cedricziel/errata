<?php

declare(strict_types=1);

namespace App\Service\QueryBuilder;

use App\DTO\QueryBuilder\QueryFilter;

/**
 * Provides metadata about filterable attributes including their types and operators.
 */
class AttributeMetadataService
{
    public const TYPE_STRING = 'string';
    public const TYPE_NUMERIC = 'numeric';

    /**
     * @var array<string, array{type: string, label: string, facetable: bool, facetType: string|null, facetExpanded: bool}>
     */
    private array $attributes = [
        // Event identification
        'event_type' => [
            'type' => self::TYPE_STRING,
            'label' => 'Event Type',
            'facetable' => true,
            'facetType' => 'single',
            'facetExpanded' => true,
        ],
        'severity' => [
            'type' => self::TYPE_STRING,
            'label' => 'Severity',
            'facetable' => true,
            'facetType' => 'single',
            'facetExpanded' => true,
        ],
        'environment' => [
            'type' => self::TYPE_STRING,
            'label' => 'Environment',
            'facetable' => true,
            'facetType' => 'single',
            'facetExpanded' => true,
        ],

        // Device info
        'device_model' => [
            'type' => self::TYPE_STRING,
            'label' => 'Device Model',
            'facetable' => true,
            'facetType' => 'multi',
            'facetExpanded' => true,
        ],
        'os_name' => [
            'type' => self::TYPE_STRING,
            'label' => 'OS Name',
            'facetable' => true,
            'facetType' => 'multi',
            'facetExpanded' => false,
        ],
        'os_version' => [
            'type' => self::TYPE_STRING,
            'label' => 'OS Version',
            'facetable' => true,
            'facetType' => 'multi',
            'facetExpanded' => true,
        ],

        // App info
        'app_version' => [
            'type' => self::TYPE_STRING,
            'label' => 'App Version',
            'facetable' => true,
            'facetType' => 'multi',
            'facetExpanded' => true,
        ],
        'app_build' => [
            'type' => self::TYPE_STRING,
            'label' => 'App Build',
            'facetable' => true,
            'facetType' => 'collapsible',
            'facetExpanded' => false,
        ],
        'bundle_id' => [
            'type' => self::TYPE_STRING,
            'label' => 'Bundle ID',
            'facetable' => false,
            'facetType' => null,
            'facetExpanded' => false,
        ],

        // Tracing
        'trace_id' => [
            'type' => self::TYPE_STRING,
            'label' => 'Trace ID',
            'facetable' => false,
            'facetType' => null,
            'facetExpanded' => false,
        ],
        'span_id' => [
            'type' => self::TYPE_STRING,
            'label' => 'Span ID',
            'facetable' => false,
            'facetType' => null,
            'facetExpanded' => false,
        ],
        'operation' => [
            'type' => self::TYPE_STRING,
            'label' => 'Operation',
            'facetable' => true,
            'facetType' => 'collapsible',
            'facetExpanded' => false,
        ],
        'span_status' => [
            'type' => self::TYPE_STRING,
            'label' => 'Span Status',
            'facetable' => true,
            'facetType' => 'single',
            'facetExpanded' => false,
        ],

        // User/session
        'user_id' => [
            'type' => self::TYPE_STRING,
            'label' => 'User ID',
            'facetable' => true,
            'facetType' => 'collapsible',
            'facetExpanded' => false,
        ],
        'session_id' => [
            'type' => self::TYPE_STRING,
            'label' => 'Session ID',
            'facetable' => false,
            'facetType' => null,
            'facetExpanded' => false,
        ],

        // Locale
        'locale' => [
            'type' => self::TYPE_STRING,
            'label' => 'Locale',
            'facetable' => true,
            'facetType' => 'collapsible',
            'facetExpanded' => false,
        ],
        'timezone' => [
            'type' => self::TYPE_STRING,
            'label' => 'Timezone',
            'facetable' => false,
            'facetType' => null,
            'facetExpanded' => false,
        ],

        // Error info
        'message' => [
            'type' => self::TYPE_STRING,
            'label' => 'Message',
            'facetable' => false,
            'facetType' => null,
            'facetExpanded' => false,
        ],
        'exception_type' => [
            'type' => self::TYPE_STRING,
            'label' => 'Exception Type',
            'facetable' => true,
            'facetType' => 'multi',
            'facetExpanded' => false,
        ],

        // Numeric fields
        'duration_ms' => [
            'type' => self::TYPE_NUMERIC,
            'label' => 'Duration (ms)',
            'facetable' => false,
            'facetType' => null,
            'facetExpanded' => false,
        ],
        'memory_used' => [
            'type' => self::TYPE_NUMERIC,
            'label' => 'Memory Used',
            'facetable' => false,
            'facetType' => null,
            'facetExpanded' => false,
        ],
        'memory_total' => [
            'type' => self::TYPE_NUMERIC,
            'label' => 'Memory Total',
            'facetable' => false,
            'facetType' => null,
            'facetExpanded' => false,
        ],
        'disk_free' => [
            'type' => self::TYPE_NUMERIC,
            'label' => 'Disk Free',
            'facetable' => false,
            'facetType' => null,
            'facetExpanded' => false,
        ],
        'battery_level' => [
            'type' => self::TYPE_NUMERIC,
            'label' => 'Battery Level',
            'facetable' => false,
            'facetType' => null,
            'facetExpanded' => false,
        ],
    ];

    /**
     * @return array<string, array{type: string, label: string, facetable: bool, facetType: string|null, facetExpanded: bool}>
     */
    public function getAllAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @return array<string, string>
     */
    public function getFilterableAttributes(): array
    {
        $filterable = [];
        foreach ($this->attributes as $key => $meta) {
            $filterable[$key] = $meta['label'];
        }

        return $filterable;
    }

    /**
     * @return array<string, array{type: string, label: string, facetType: string, facetExpanded: bool}>
     */
    public function getFacetableAttributes(): array
    {
        $facetable = [];
        foreach ($this->attributes as $key => $meta) {
            if ($meta['facetable']) {
                $facetable[$key] = [
                    'type' => $meta['type'],
                    'label' => $meta['label'],
                    'facetType' => $meta['facetType'] ?? 'multi',
                    'facetExpanded' => $meta['facetExpanded'],
                ];
            }
        }

        return $facetable;
    }

    /**
     * @return array<string>
     */
    public function getGroupableAttributes(): array
    {
        return [
            'event_type',
            'severity',
            'environment',
            'device_model',
            'os_name',
            'os_version',
            'app_version',
            'user_id',
            'operation',
            'exception_type',
        ];
    }

    public function getAttributeType(string $attribute): ?string
    {
        return $this->attributes[$attribute]['type'] ?? null;
    }

    public function getAttributeLabel(string $attribute): string
    {
        return $this->attributes[$attribute]['label'] ?? $attribute;
    }

    /**
     * @return array<string>
     */
    public function getOperatorsForAttribute(string $attribute): array
    {
        $type = $this->getAttributeType($attribute);

        if (self::TYPE_NUMERIC === $type) {
            return QueryFilter::getNumericOperators();
        }

        return QueryFilter::getStringOperators();
    }

    /**
     * @return array<string, string>
     */
    public function getOperatorLabelsForAttribute(string $attribute): array
    {
        $operators = $this->getOperatorsForAttribute($attribute);
        $labels = QueryFilter::getOperatorLabels();

        return array_intersect_key($labels, array_flip($operators));
    }

    /**
     * @return array{attributes: array<string, string>, operators: array<string, string>}
     */
    public function getFilterBuilderData(): array
    {
        return [
            'attributes' => $this->getFilterableAttributes(),
            'operators' => QueryFilter::getOperatorLabels(),
        ];
    }
}
