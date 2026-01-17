<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\WideEventPayload;
use App\Message\ProcessEvent;
use App\Security\ApiKeyAuthenticator;
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
    ) {
    }

    /**
     * Submit a single event.
     */
    #[Route('/events', name: 'events_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $project = ApiKeyAuthenticator::getProject($request);
        $apiKey = ApiKeyAuthenticator::getApiKey($request);

        if (null === $project || null === $apiKey) {
            return $this->errorResponse('Unauthorized', Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->errorResponse('Invalid JSON payload', Response::HTTP_BAD_REQUEST);
        }

        $payload = WideEventPayload::fromArray($data);
        $errors = $this->validator->validate($payload);

        if (count($errors) > 0) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[$error->getPropertyPath()] = $error->getMessage();
            }

            return $this->errorResponse('Validation failed', Response::HTTP_BAD_REQUEST, $messages);
        }

        // Dispatch event for async processing
        $this->messageBus->dispatch(new ProcessEvent(
            eventData: $payload->toArray(),
            projectId: $project->getPublicId()->toRfc4122(),
            environment: $apiKey->getEnvironment(),
        ));

        return new JsonResponse([
            'status' => 'accepted',
            'message' => 'Event queued for processing',
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Submit a batch of events.
     */
    #[Route('/events/batch', name: 'events_batch', methods: ['POST'])]
    public function batch(Request $request): JsonResponse
    {
        $project = ApiKeyAuthenticator::getProject($request);
        $apiKey = ApiKeyAuthenticator::getApiKey($request);

        if (null === $project || null === $apiKey) {
            return $this->errorResponse('Unauthorized', Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->errorResponse('Invalid JSON payload', Response::HTTP_BAD_REQUEST);
        }

        // Support both { "events": [...] } and direct array [...]
        $events = $data['events'] ?? $data;

        if (!is_array($events) || empty($events)) {
            return $this->errorResponse('No events provided', Response::HTTP_BAD_REQUEST);
        }

        // Limit batch size
        if (count($events) > 100) {
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
                projectId: $project->getPublicId()->toRfc4122(),
                environment: $apiKey->getEnvironment(),
            ));

            ++$accepted;
        }

        $response = [
            'status' => 'accepted',
            'accepted' => $accepted,
            'total' => count($events),
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return new JsonResponse($response, Response::HTTP_ACCEPTED);
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
