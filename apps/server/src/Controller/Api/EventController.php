<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\WideEventPayload;
use App\Message\ProcessEvent;
use App\Security\ApiKeyAuthenticator;
use App\Service\Telemetry\TracerFactory;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1', name: 'api_v1_')]
class EventController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatorInterface $validator,
        private readonly TracerFactory $tracerFactory,
    ) {
    }

    /**
     * Submit a single event.
     */
    #[Route('/events', name: 'events_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $span = $this->startSpan('event.ingest');

        try {
            $project = ApiKeyAuthenticator::getProject($request);
            $apiKey = ApiKeyAuthenticator::getApiKey($request);

            if (null === $project || null === $apiKey) {
                $this->setSpanError($span, 'Unauthorized');

                return $this->errorResponse('Unauthorized', Response::HTTP_UNAUTHORIZED);
            }

            $projectId = $project->getPublicId()->toRfc4122();
            $span?->setAttribute('project.id', $projectId);

            $data = json_decode($request->getContent(), true);

            if (!is_array($data)) {
                $this->setSpanError($span, 'Invalid JSON payload');

                return $this->errorResponse('Invalid JSON payload', Response::HTTP_BAD_REQUEST);
            }

            $payload = WideEventPayload::fromArray($data);
            $errors = $this->validator->validate($payload);

            if (count($errors) > 0) {
                $messages = [];
                foreach ($errors as $error) {
                    $messages[$error->getPropertyPath()] = $error->getMessage();
                }

                $this->setSpanError($span, 'Validation failed');

                return $this->errorResponse('Validation failed', Response::HTTP_BAD_REQUEST, $messages);
            }

            $eventType = $payload->eventType ?? 'unknown';
            $span?->setAttribute('event.type', $eventType);

            // Dispatch event for async processing
            $this->messageBus->dispatch(new ProcessEvent(
                eventData: $payload->toArray(),
                projectId: $projectId,
                environment: $apiKey->getEnvironment(),
            ));

            $span?->setStatus(StatusCode::STATUS_OK);

            return new JsonResponse([
                'status' => 'accepted',
                'message' => 'Event queued for processing',
            ], Response::HTTP_ACCEPTED);
        } finally {
            $span?->end();
        }
    }

    /**
     * Submit a batch of events.
     */
    #[Route('/events/batch', name: 'events_batch', methods: ['POST'])]
    public function batch(Request $request): JsonResponse
    {
        $span = $this->startSpan('event.ingest_batch');

        try {
            $project = ApiKeyAuthenticator::getProject($request);
            $apiKey = ApiKeyAuthenticator::getApiKey($request);

            if (null === $project || null === $apiKey) {
                $this->setSpanError($span, 'Unauthorized');

                return $this->errorResponse('Unauthorized', Response::HTTP_UNAUTHORIZED);
            }

            $projectId = $project->getPublicId()->toRfc4122();
            $span?->setAttribute('project.id', $projectId);

            $data = json_decode($request->getContent(), true);

            if (!is_array($data)) {
                $this->setSpanError($span, 'Invalid JSON payload');

                return $this->errorResponse('Invalid JSON payload', Response::HTTP_BAD_REQUEST);
            }

            // Support both { "events": [...] } and direct array [...]
            $events = $data['events'] ?? $data;

            if (!is_array($events) || empty($events)) {
                $this->setSpanError($span, 'No events provided');

                return $this->errorResponse('No events provided', Response::HTTP_BAD_REQUEST);
            }

            $batchSize = count($events);
            $span?->setAttribute('batch.size', $batchSize);

            // Limit batch size
            if ($batchSize > 100) {
                $this->setSpanError($span, 'Batch size exceeds maximum');

                return $this->errorResponse('Batch size exceeds maximum of 100 events', Response::HTTP_BAD_REQUEST);
            }

            $accepted = 0;
            $errors = [];

            foreach ($events as $index => $eventData) {
                if (!is_array($eventData)) {
                    $errors[$index] = 'Invalid event format';
                    continue;
                }

                $payload = WideEventPayload::fromArray($eventData);
                $validationErrors = $this->validator->validate($payload);

                if (count($validationErrors) > 0) {
                    $messages = [];
                    foreach ($validationErrors as $error) {
                        $messages[$error->getPropertyPath()] = $error->getMessage();
                    }
                    $errors[$index] = $messages;
                    continue;
                }

                // Dispatch event for async processing
                $this->messageBus->dispatch(new ProcessEvent(
                    eventData: $payload->toArray(),
                    projectId: $projectId,
                    environment: $apiKey->getEnvironment(),
                ));

                ++$accepted;
            }

            $span?->setAttribute('batch.accepted', $accepted);
            $span?->setAttribute('batch.errors', count($errors));
            $span?->setStatus(StatusCode::STATUS_OK);

            $response = [
                'status' => 'accepted',
                'accepted' => $accepted,
                'total' => $batchSize,
            ];

            if (!empty($errors)) {
                $response['errors'] = $errors;
            }

            return new JsonResponse($response, Response::HTTP_ACCEPTED);
        } finally {
            $span?->end();
        }
    }

    /**
     * Health check endpoint (no authentication required).
     */
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    private function startSpan(string $name): ?SpanInterface
    {
        if (!$this->tracerFactory->isEnabled()) {
            return null;
        }

        return $this->tracerFactory->createTracer('api')
            ->spanBuilder($name)
            ->startSpan();
    }

    private function setSpanError(?SpanInterface $span, string $message): void
    {
        $span?->setStatus(StatusCode::STATUS_ERROR, $message);
    }

    /**
     * Create a JSON error response.
     *
     * @param array<string, mixed>|null $details
     */
    private function errorResponse(string $message, int $status, ?array $details = null): JsonResponse
    {
        $response = [
            'error' => $this->getErrorCode($status),
            'message' => $message,
        ];

        if (null !== $details) {
            $response['details'] = $details;
        }

        return new JsonResponse($response, $status);
    }

    /**
     * Get error code from HTTP status.
     */
    private function getErrorCode(int $status): string
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'bad_request',
            Response::HTTP_UNAUTHORIZED => 'unauthorized',
            Response::HTTP_FORBIDDEN => 'forbidden',
            Response::HTTP_NOT_FOUND => 'not_found',
            Response::HTTP_TOO_MANY_REQUESTS => 'rate_limited',
            default => 'error',
        };
    }
}
