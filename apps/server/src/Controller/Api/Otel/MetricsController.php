<?php

declare(strict_types=1);

namespace App\Controller\Api\Otel;

use App\Message\ProcessEvent;
use App\Security\ApiKeyAuthenticator;
use App\Service\Otel\MetricMapper;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OTLP Metrics ingestion endpoint.
 */
#[Route('/v1')]
class MetricsController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly MetricMapper $metricMapper,
    ) {
    }

    /**
     * Ingest OTLP metrics.
     */
    #[Route('/metrics', name: 'otel_metrics', methods: ['POST'])]
    public function ingest(Request $request): Response
    {
        $project = ApiKeyAuthenticator::getProject($request);
        $apiKey = ApiKeyAuthenticator::getApiKey($request);

        $contentType = $request->headers->get('Content-Type', '');
        $isProtobuf = str_contains($contentType, 'application/x-protobuf');

        if (null === $project || null === $apiKey) {
            return $this->createErrorResponse('unauthorized', 'Invalid or missing API key', Response::HTTP_UNAUTHORIZED, $isProtobuf);
        }

        $content = $request->getContent();
        if ('' === $content) {
            return $this->createErrorResponse('bad_request', 'Empty payload', Response::HTTP_BAD_REQUEST, $isProtobuf);
        }

        // Handle gzip-compressed content (OTLP collectors often send compressed data)
        $contentEncoding = $request->headers->get('Content-Encoding', '');
        if ('gzip' === $contentEncoding) {
            $decompressed = @gzdecode($content);
            if (false === $decompressed) {
                return $this->createErrorResponse('bad_request', 'Failed to decompress gzip content', Response::HTTP_BAD_REQUEST, $isProtobuf);
            }
            $content = $decompressed;
        }

        try {
            $metricsRequest = new ExportMetricsServiceRequest();
            if ($isProtobuf) {
                $metricsRequest->mergeFromString($content);
            } else {
                if (!$this->isValidJson($content)) {
                    return $this->createErrorResponse('bad_request', 'Invalid JSON payload', Response::HTTP_BAD_REQUEST, $isProtobuf);
                }
                $metricsRequest->mergeFromJsonString($content);
            }
        } catch (\Throwable $e) {
            return $this->createErrorResponse('bad_request', 'Failed to parse OTLP metrics request: '.$e->getMessage(), Response::HTTP_BAD_REQUEST, $isProtobuf);
        }

        $projectId = $project->getPublicId()->toRfc4122();
        $environment = $apiKey->getEnvironment();

        foreach ($this->metricMapper->mapToEvents($metricsRequest) as $eventData) {
            $this->messageBus->dispatch(new ProcessEvent(
                eventData: $eventData,
                projectId: $projectId,
                environment: $environment,
            ));
        }

        return $this->createSuccessResponse($isProtobuf);
    }

    /**
     * Create a success response in the appropriate format.
     */
    private function createSuccessResponse(bool $isProtobuf): Response
    {
        $response = new ExportMetricsServiceResponse();

        if ($isProtobuf) {
            return new Response(
                $response->serializeToString(),
                Response::HTTP_OK,
                [
                    'Content-Type' => 'application/x-protobuf',
                    'Content-Encoding' => 'identity',
                ]
            );
        }

        return new JsonResponse([
            'partialSuccess' => new \stdClass(),
        ], Response::HTTP_OK, [
            'Content-Encoding' => 'identity',
        ]);
    }

    /**
     * Create an error response in the appropriate format.
     */
    private function createErrorResponse(string $error, string $message, int $statusCode, bool $isProtobuf): Response
    {
        return new JsonResponse([
            'error' => $error,
            'message' => $message,
        ], $statusCode, [
            'Content-Encoding' => 'identity',
        ]);
    }

    /**
     * Check if string is valid JSON.
     */
    private function isValidJson(string $content): bool
    {
        json_decode($content, true);

        return JSON_ERROR_NONE === json_last_error();
    }
}
