<?php

declare(strict_types=1);

namespace App\Controller\Api\Otel;

use App\Message\ProcessEvent;
use App\Security\ApiKeyAuthenticator;
use App\Service\Otel\LogMapper;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OTLP Logs ingestion endpoint.
 */
#[Route('/v1')]
class LogsController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LogMapper $logMapper,
    ) {
    }

    /**
     * Ingest OTLP logs.
     */
    #[Route('/logs', name: 'otel_logs', methods: ['POST'])]
    public function ingest(Request $request): JsonResponse
    {
        $project = ApiKeyAuthenticator::getProject($request);
        $apiKey = ApiKeyAuthenticator::getApiKey($request);

        if (null === $project || null === $apiKey) {
            return new JsonResponse([
                'error' => 'unauthorized',
                'message' => 'Invalid or missing API key',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $content = $request->getContent();
        if ('' === $content) {
            return new JsonResponse([
                'error' => 'bad_request',
                'message' => 'Empty payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        $contentType = $request->headers->get('Content-Type', '');
        $isProtobuf = str_contains($contentType, 'application/x-protobuf');

        try {
            $logsRequest = new ExportLogsServiceRequest();
            if ($isProtobuf) {
                $logsRequest->mergeFromString($content);
            } else {
                if (!$this->isValidJson($content)) {
                    return new JsonResponse([
                        'error' => 'bad_request',
                        'message' => 'Invalid JSON payload',
                    ], Response::HTTP_BAD_REQUEST);
                }
                $logsRequest->mergeFromJsonString($content);
            }
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'bad_request',
                'message' => 'Failed to parse OTLP logs request: '.$e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        $projectId = $project->getPublicId()->toRfc4122();
        $environment = $apiKey->getEnvironment();

        foreach ($this->logMapper->mapToEvents($logsRequest) as $eventData) {
            $this->messageBus->dispatch(new ProcessEvent(
                eventData: $eventData,
                projectId: $projectId,
                environment: $environment,
            ));
        }

        return new JsonResponse([
            'partialSuccess' => new \stdClass(),
        ], Response::HTTP_OK);
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
