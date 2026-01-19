<?php

declare(strict_types=1);

namespace App\Service\Telemetry;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

final class TracerFactory
{
    private ?TracerProviderInterface $tracerProvider = null;
    private ?TextMapPropagatorInterface $propagator = null;

    public function __construct(
        private readonly bool $enabled,
        private readonly string $serviceName,
        private readonly string $serviceVersion,
        private readonly string $exporterEndpoint,
        private readonly string $samplerType,
        private readonly float $samplerArg,
        private readonly string $environment,
        private readonly string $exporterHeaders = '',
    ) {
    }

    public function createTracer(string $instrumentationName = 'errata'): TracerInterface
    {
        return $this->getTracerProvider()->getTracer(
            $instrumentationName,
            $this->serviceVersion,
        );
    }

    public function getTracerProvider(): TracerProviderInterface
    {
        if (null === $this->tracerProvider) {
            $this->tracerProvider = $this->buildTracerProvider();
        }

        return $this->tracerProvider;
    }

    public function getPropagator(): TextMapPropagatorInterface
    {
        if (null === $this->propagator) {
            $this->propagator = new MultiTextMapPropagator([
                TraceContextPropagator::getInstance(),
            ]);
        }

        return $this->propagator;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Flush any pending spans. Call this on shutdown.
     */
    public function shutdown(): bool
    {
        if ($this->tracerProvider instanceof TracerProvider) {
            return $this->tracerProvider->shutdown();
        }

        return true;
    }

    /**
     * Force flush pending spans without shutdown.
     */
    public function forceFlush(): bool
    {
        if ($this->tracerProvider instanceof TracerProvider) {
            return $this->tracerProvider->forceFlush();
        }

        return true;
    }

    private function buildTracerProvider(): TracerProviderInterface
    {
        if (!$this->enabled) {
            return new TracerProvider();
        }

        $resource = ResourceInfo::create(Attributes::create([
            ResourceAttributes::SERVICE_NAME => $this->serviceName,
            ResourceAttributes::SERVICE_VERSION => $this->serviceVersion,
            ResourceAttributes::DEPLOYMENT_ENVIRONMENT_NAME => $this->environment,
        ]));

        $exporter = $this->createExporter();
        $sampler = $this->createSampler();

        // Use SimpleSpanProcessor for dev (immediate export) or BatchSpanProcessor for prod
        $clock = ClockFactory::getDefault();
        $spanProcessor = 'dev' === $this->environment || 'console' === $this->exporterEndpoint
            ? new SimpleSpanProcessor($exporter)
            : new BatchSpanProcessor($exporter, $clock);

        return new TracerProvider(
            spanProcessors: [$spanProcessor],
            sampler: $sampler,
            resource: $resource,
        );
    }

    private function createExporter(): \OpenTelemetry\SDK\Trace\SpanExporterInterface
    {
        // Console exporter for debugging
        if ('console' === $this->exporterEndpoint) {
            $transport = (new StreamTransportFactory())->create(
                'php://stdout',
                'application/json',
            );

            return new ConsoleSpanExporter($transport);
        }

        // Parse OTEL_EXPORTER_OTLP_HEADERS format: key1=value1,key2=value2
        $headers = $this->parseHeaders($this->exporterHeaders);

        // OTLP HTTP exporter with JSON format
        $transport = (new \OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory())->create(
            $this->exporterEndpoint.'/v1/traces',
            'application/json',
            $headers,
        );

        return new \OpenTelemetry\Contrib\Otlp\SpanExporter($transport);
    }

    /**
     * Parse OTEL_EXPORTER_OTLP_HEADERS format: key1=value1,key2=value2.
     *
     * @return array<string, string>
     */
    private function parseHeaders(string $headersString): array
    {
        $headers = [];
        if ('' === $headersString) {
            return $headers;
        }

        foreach (explode(',', $headersString) as $header) {
            $parts = explode('=', $header, 2);
            if (2 === \count($parts)) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $headers;
    }

    private function createSampler(): \OpenTelemetry\SDK\Trace\SamplerInterface
    {
        return match ($this->samplerType) {
            'always_on' => new AlwaysOnSampler(),
            'always_off' => new \OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler(),
            'traceidratio' => new TraceIdRatioBasedSampler($this->samplerArg),
            'parentbased_always_on' => new ParentBased(new AlwaysOnSampler()),
            'parentbased_always_off' => new ParentBased(new \OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler()),
            'parentbased_traceidratio' => new ParentBased(new TraceIdRatioBasedSampler($this->samplerArg)),
            default => new ParentBased(new AlwaysOnSampler()),
        };
    }
}
