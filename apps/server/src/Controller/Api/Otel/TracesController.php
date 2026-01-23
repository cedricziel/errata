<?php

declare(strict_types=1);

namespace App\Controller\Api\Otel;

use App\Message\ProcessEvent;
use App\Security\ApiKeyAuthenticator;
use App\Service\Otel\TraceMapper;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OTLP Traces ingestion endpoint.
 */
#[Route('/v1')]
class TracesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TraceMapper $traceMapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Ingest OTLP traces.
     */
    #[Route('/traces', name: 'otel_traces', methods: ['POST'])]
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
            $this->logger->warning('OTLP traces: Empty payload received', [
                'content_type' => $contentType,
                'x_errata_key' => substr($request->headers->get('X-Errata-Key', ''), 0, 20).'...',
            ]);

            return $this->createErrorResponse('bad_request', 'Empty payload', Response::HTTP_BAD_REQUEST, $isProtobuf);
        }

        $this->logger->debug('OTLP traces: Request received', [
            'content_type' => $contentType,
            'is_protobuf' => $isProtobuf,
            'payload_size' => strlen($content),
            'project_id' => $project->getPublicId()->toRfc4122(),
        ]);

        try {
            $traceRequest = new ExportTraceServiceRequest();
            if ($isProtobuf) {
                $traceRequest->mergeFromString($content);
            } else {
                if (!$this->isValidJson($content)) {
                    $this->logger->error('OTLP traces: Invalid JSON payload', [
                        'payload_preview' => substr($content, 0, 200),
                    ]);

                    return $this->createErrorResponse('bad_request', 'Invalid JSON payload', Response::HTTP_BAD_REQUEST, $isProtobuf);
                }
                $traceRequest->mergeFromJsonString($content);
            }
        } catch (\Throwable $e) {
            $this->logger->error('OTLP traces: Failed to parse request', [
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
                'content_type' => $contentType,
                'is_protobuf' => $isProtobuf,
                'payload_size' => strlen($content),
                'payload_preview' => $isProtobuf ? bin2hex(substr($content, 0, 100)) : substr($content, 0, 200),
            ]);

            return $this->createErrorResponse('bad_request', 'Failed to parse OTLP trace request: '.$e->getMessage(), Response::HTTP_BAD_REQUEST, $isProtobuf);
        }

        $projectId = $project->getPublicId()->toRfc4122();
        $environment = $apiKey->getEnvironment();

        foreach ($this->traceMapper->mapToEvents($traceRequest) as $eventData) {
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
        $response = new ExportTraceServiceResponse();

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
        // For protobuf clients, still return JSON errors as they're more readable
        // and the OTLP spec allows this for error responses
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
