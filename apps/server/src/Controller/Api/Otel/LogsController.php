<?php

declare(strict_types=1);

namespace App\Controller\Api\Otel;

use App\DTO\Otel\Logs\ExportLogsServiceRequest;
use App\Message\ProcessEvent;
use App\Security\ApiKeyAuthenticator;
use App\Service\Otel\LogMapper;
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
        $contentType = $request->headers->get('Content-Type', '');

        if (str_contains($contentType, 'application/x-protobuf')) {
            return new JsonResponse([
                'error' => 'unsupported_media_type',
                'message' => 'Protobuf format is not supported. Please use application/json.',
            ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $project = ApiKeyAuthenticator::getProject($request);
        $apiKey = ApiKeyAuthenticator::getApiKey($request);

        if (null === $project || null === $apiKey) {
            return new JsonResponse([
                'error' => 'unauthorized',
                'message' => 'Invalid or missing API key',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $content = $request->getContent();
        $data = json_decode($content, true);

        if (!is_array($data)) {
            return new JsonResponse([
                'error' => 'bad_request',
                'message' => 'Invalid JSON payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $logsRequest = ExportLogsServiceRequest::fromArray($data);
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
}
