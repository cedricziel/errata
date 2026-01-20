<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\QueryStatus;
use App\Service\QueryBuilder\AsyncQueryResultStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for Server-Sent Events (SSE) streaming of query results.
 */
#[IsGranted('ROLE_USER')]
#[Route('/query')]
class QuerySseController extends AbstractController
{
    private const HEARTBEAT_INTERVAL = 15; // seconds
    private const POLL_INTERVAL = 500000; // 0.5 seconds in microseconds
    private const MAX_EXECUTION_TIME = 120; // 2 minutes

    public function __construct(
        private readonly AsyncQueryResultStore $resultStore,
    ) {
    }

    /**
     * SSE endpoint for streaming query status and results.
     */
    #[Route('/stream/{queryId}', name: 'query_stream', methods: ['GET'])]
    public function streamResults(string $queryId): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($queryId) {
            // Disable output buffering
            if (ob_get_level()) {
                ob_end_clean();
            }

            // Set script execution time
            set_time_limit(self::MAX_EXECUTION_TIME + 10);

            $startTime = time();
            $lastHeartbeat = time();
            $lastStatus = null;
            $lastProgress = -1;

            while (true) {
                // Check for timeout
                if ((time() - $startTime) >= self::MAX_EXECUTION_TIME) {
                    $this->sendSseEvent('error', ['message' => 'Query execution timeout']);
                    break;
                }

                // Get current state
                $state = $this->resultStore->getQueryState($queryId);

                if (null === $state) {
                    $this->sendSseEvent('error', ['message' => 'Query not found']);
                    break;
                }

                $status = QueryStatus::from($state['status']);
                $progress = $state['progress'] ?? 0;

                // Send status change event
                if ($lastStatus !== $status->value) {
                    $this->sendSseEvent('status', [
                        'status' => $status->value,
                        'progress' => $progress,
                    ]);
                    $lastStatus = $status->value;
                }

                // Send progress update
                if ($progress !== $lastProgress) {
                    $this->sendSseEvent('progress', ['progress' => $progress]);
                    $lastProgress = $progress;
                }

                // Handle terminal states
                if ($status->isTerminal()) {
                    if (QueryStatus::COMPLETED === $status) {
                        $this->sendSseEvent('result', [
                            'events' => $state['result']['events'] ?? [],
                            'total' => $state['result']['total'] ?? 0,
                            'facets' => $state['result']['facets'] ?? [],
                            'groupedResults' => $state['result']['groupedResults'] ?? [],
                            'page' => $state['result']['page'] ?? 1,
                            'limit' => $state['result']['limit'] ?? 50,
                        ]);
                    } elseif (QueryStatus::FAILED === $status) {
                        $this->sendSseEvent('error', ['message' => $state['error'] ?? 'Unknown error']);
                    } elseif (QueryStatus::CANCELLED === $status) {
                        $this->sendSseEvent('cancelled', ['message' => 'Query was cancelled']);
                    }
                    break;
                }

                // Send heartbeat if needed
                if ((time() - $lastHeartbeat) >= self::HEARTBEAT_INTERVAL) {
                    $this->sendSseEvent('heartbeat', ['time' => time()]);
                    $lastHeartbeat = time();
                }

                // Flush and sleep
                if (connection_aborted()) {
                    break;
                }

                usleep(self::POLL_INTERVAL);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no'); // Disable nginx buffering

        return $response;
    }

    /**
     * Cancel a running query.
     */
    #[Route('/cancel/{queryId}', name: 'query_cancel', methods: ['POST'])]
    public function cancel(string $queryId): JsonResponse
    {
        $state = $this->resultStore->getQueryState($queryId);

        if (null === $state) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Query not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $status = QueryStatus::from($state['status']);

        if ($status->isTerminal()) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Query has already completed',
            ], Response::HTTP_BAD_REQUEST);
        }

        $cancelled = $this->resultStore->requestCancellation($queryId);

        return new JsonResponse([
            'success' => $cancelled,
            'message' => $cancelled ? 'Cancellation requested' : 'Could not cancel query',
        ]);
    }

    /**
     * Get the current status of a query (non-streaming).
     */
    #[Route('/status/{queryId}', name: 'query_status', methods: ['GET'])]
    public function status(string $queryId): JsonResponse
    {
        $state = $this->resultStore->getQueryState($queryId);

        if (null === $state) {
            return new JsonResponse([
                'error' => 'Query not found',
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'queryId' => $queryId,
            'status' => $state['status'],
            'progress' => $state['progress'] ?? 0,
            'error' => $state['error'] ?? null,
            'hasResult' => null !== ($state['result'] ?? null),
        ]);
    }

    /**
     * Send an SSE event.
     *
     * @param array<string, mixed> $data
     */
    private function sendSseEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data)."\n\n";
        flush();
    }
}
