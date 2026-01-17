<?php

declare(strict_types=1);

namespace App\DTO\Otel\Metrics;

/**
 * Represents a single OTLP Metric.
 */
class Metric
{
    public string $name = '';

    public string $description = '';

    public string $unit = '';

    public ?Gauge $gauge = null;

    public ?Sum $sum = null;

    public ?Histogram $histogram = null;

    /**
     * Get the metric type.
     */
    public function getType(): string
    {
        if (null !== $this->gauge) {
            return 'gauge';
        }
        if (null !== $this->sum) {
            return 'sum';
        }
        if (null !== $this->histogram) {
            return 'histogram';
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $metric = new self();
        $metric->name = (string) ($data['name'] ?? '');
        $metric->description = (string) ($data['description'] ?? '');
        $metric->unit = (string) ($data['unit'] ?? '');

        if (isset($data['gauge']) && is_array($data['gauge'])) {
            $metric->gauge = Gauge::fromArray($data['gauge']);
        }
        if (isset($data['sum']) && is_array($data['sum'])) {
            $metric->sum = Sum::fromArray($data['sum']);
        }
        if (isset($data['histogram']) && is_array($data['histogram'])) {
            $metric->histogram = Histogram::fromArray($data['histogram']);
        }

        return $metric;
    }
}
