<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * DTO for incoming wide event payloads from the API.
 */
class WideEventPayload
{
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['crash', 'error', 'log', 'metric', 'span'])]
    public string $event_type;

    #[Assert\Choice(choices: ['trace', 'debug', 'info', 'warning', 'error', 'fatal'], message: 'Invalid severity level')]
    public ?string $severity = null;

    public ?string $message = null;

    public ?string $exception_type = null;

    /** @var array<mixed>|null */
    public ?array $stack_trace = null;

    public ?string $app_version = null;

    public ?string $app_build = null;

    public ?string $bundle_id = null;

    #[Assert\Choice(choices: ['production', 'staging', 'development'])]
    public ?string $environment = null;

    public ?string $device_model = null;

    public ?string $device_id = null;

    public ?string $os_name = null;

    public ?string $os_version = null;

    public ?string $locale = null;

    public ?string $timezone = null;

    public ?int $memory_used = null;

    public ?int $memory_total = null;

    public ?int $disk_free = null;

    public ?float $battery_level = null;

    public ?string $trace_id = null;

    public ?string $span_id = null;

    public ?string $parent_span_id = null;

    public ?string $operation = null;

    public ?float $duration_ms = null;

    public ?string $span_status = null;

    public ?string $metric_name = null;

    public ?float $metric_value = null;

    public ?string $metric_unit = null;

    public ?string $user_id = null;

    public ?string $session_id = null;

    /** @var array<string, mixed>|null */
    public ?array $tags = null;

    /** @var array<string, mixed>|null */
    public ?array $context = null;

    /** @var array<mixed>|null */
    public ?array $breadcrumbs = null;

    /**
     * Convert the DTO to an array for storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_type' => $this->event_type,
            'severity' => $this->severity,
            'message' => $this->message,
            'exception_type' => $this->exception_type,
            'stack_trace' => $this->stack_trace,
            'app_version' => $this->app_version,
            'app_build' => $this->app_build,
            'bundle_id' => $this->bundle_id,
            'environment' => $this->environment,
            'device_model' => $this->device_model,
            'device_id' => $this->device_id,
            'os_name' => $this->os_name,
            'os_version' => $this->os_version,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'memory_used' => $this->memory_used,
            'memory_total' => $this->memory_total,
            'disk_free' => $this->disk_free,
            'battery_level' => $this->battery_level,
            'trace_id' => $this->trace_id,
            'span_id' => $this->span_id,
            'parent_span_id' => $this->parent_span_id,
            'operation' => $this->operation,
            'duration_ms' => $this->duration_ms,
            'span_status' => $this->span_status,
            'metric_name' => $this->metric_name,
            'metric_value' => $this->metric_value,
            'metric_unit' => $this->metric_unit,
            'user_id' => $this->user_id,
            'session_id' => $this->session_id,
            'tags' => $this->tags,
            'context' => $this->context,
            'breadcrumbs' => $this->breadcrumbs,
        ];
    }

    /**
     * Create a DTO from an array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $dto = new self();

        $dto->event_type = $data['event_type'] ?? 'error';
        $dto->severity = $data['severity'] ?? null;
        $dto->message = $data['message'] ?? null;
        $dto->exception_type = $data['exception_type'] ?? null;
        $dto->stack_trace = $data['stack_trace'] ?? null;
        $dto->app_version = $data['app_version'] ?? null;
        $dto->app_build = $data['app_build'] ?? null;
        $dto->bundle_id = $data['bundle_id'] ?? null;
        $dto->environment = $data['environment'] ?? null;
        $dto->device_model = $data['device_model'] ?? null;
        $dto->device_id = $data['device_id'] ?? null;
        $dto->os_name = $data['os_name'] ?? null;
        $dto->os_version = $data['os_version'] ?? null;
        $dto->locale = $data['locale'] ?? null;
        $dto->timezone = $data['timezone'] ?? null;
        $dto->memory_used = isset($data['memory_used']) ? (int) $data['memory_used'] : null;
        $dto->memory_total = isset($data['memory_total']) ? (int) $data['memory_total'] : null;
        $dto->disk_free = isset($data['disk_free']) ? (int) $data['disk_free'] : null;
        $dto->battery_level = isset($data['battery_level']) ? (float) $data['battery_level'] : null;
        $dto->trace_id = $data['trace_id'] ?? null;
        $dto->span_id = $data['span_id'] ?? null;
        $dto->parent_span_id = $data['parent_span_id'] ?? null;
        $dto->operation = $data['operation'] ?? null;
        $dto->duration_ms = isset($data['duration_ms']) ? (float) $data['duration_ms'] : null;
        $dto->span_status = $data['span_status'] ?? null;
        $dto->metric_name = $data['metric_name'] ?? null;
        $dto->metric_value = isset($data['metric_value']) ? (float) $data['metric_value'] : null;
        $dto->metric_unit = $data['metric_unit'] ?? null;
        $dto->user_id = $data['user_id'] ?? null;
        $dto->session_id = $data['session_id'] ?? null;
        $dto->tags = $data['tags'] ?? null;
        $dto->context = $data['context'] ?? null;
        $dto->breadcrumbs = $data['breadcrumbs'] ?? null;

        return $dto;
    }
}
