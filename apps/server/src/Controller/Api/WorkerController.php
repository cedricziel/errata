<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * HTTP endpoint to trigger Symfony Messenger worker execution.
 *
 * This allows message consumption via HTTP request (e.g., cron) in environments
 * where persistent worker processes are not available.
 */
#[Route('/api/worker')]
class WorkerController extends AbstractController
{
    private const DEFAULT_LIMIT = 50;
    private const DEFAULT_TIME_LIMIT = 25;
    private const VALID_TRANSPORTS = ['async', 'async_query', 'async_events'];

    public function __construct(
        #[Autowire('%env(WORKER_SECRET)%')]
        private readonly string $workerSecret,
        #[Autowire(service: 'messenger.receiver_locator')]
        private readonly ServiceProviderInterface $receiverLocator,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Consume messages from a queue.
     *
     * Authentication is done via secret token (header or query parameter).
     *
     * Query parameters:
     * - transport: Transport to consume from (async, async_query, async_events). Default: async
     * - limit: Maximum number of messages to process (default: 50)
     * - time_limit: Maximum time in seconds to run (default: 25)
     */
    #[Route('/consume', name: 'worker_consume', methods: ['POST'])]
    public function consume(Request $request): JsonResponse
    {
        // Authenticate via secret token
        $secret = $request->headers->get('X-Worker-Secret')
            ?? $request->query->get('secret');

        if ($secret !== $this->workerSecret) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Get transport from query parameters
        $transport = $request->query->get('transport', 'async');
        if (!\in_array($transport, self::VALID_TRANSPORTS, true)) {
            return new JsonResponse([
                'error' => 'Invalid transport. Valid options: '.implode(', ', self::VALID_TRANSPORTS),
            ], Response::HTTP_BAD_REQUEST);
        }

        // Get limits from query parameters or use defaults
        $limit = (int) ($request->query->get('limit') ?? self::DEFAULT_LIMIT);
        $timeLimit = (int) ($request->query->get('time_limit') ?? self::DEFAULT_TIME_LIMIT);

        // Ensure reasonable bounds
        $limit = max(1, min($limit, 500));
        $timeLimit = max(1, min($timeLimit, 300));

        $startTime = time();
        $processedCount = 0;
        $failedCount = 0;

        /** @var ReceiverInterface $receiver */
        $receiver = $this->receiverLocator->get($transport);

        while ($processedCount + $failedCount < $limit) {
            // Check time limit
            if ((time() - $startTime) >= $timeLimit) {
                break;
            }

            // Get messages from the queue
            $envelopes = $receiver->get();

            if ([] === $envelopes) {
                // No more messages in queue
                break;
            }

            foreach ($envelopes as $envelope) {
                if ($processedCount + $failedCount >= $limit) {
                    break 2;
                }

                try {
                    $this->handleMessage($envelope, $receiver, $transport);
                    ++$processedCount;
                } catch (\Throwable $e) {
                    ++$failedCount;
                    $this->logger->error('Failed to process message: '.$e->getMessage(), [
                        'exception' => $e,
                    ]);
                }
            }
        }

        // Get remaining messages count
        $remaining = $this->getQueueCount($receiver);

        return new JsonResponse([
            'status' => 'completed',
            'transport' => $transport,
            'processed' => $processedCount,
            'failed' => $failedCount,
            'remaining' => $remaining,
        ]);
    }

    private function handleMessage(Envelope $envelope, ReceiverInterface $receiver, string $transport): void
    {
        // Add stamps needed for proper message handling
        $envelope = $envelope->with(
            new ReceivedStamp($transport),
            new ConsumedByWorkerStamp(),
        );

        try {
            $envelope = $this->messageBus->dispatch($envelope);

            // Check if message was handled
            $handledStamps = $envelope->all(HandledStamp::class);
            if (0 === \count($handledStamps)) {
                $this->logger->warning('No handler processed the message');
            }

            // Acknowledge the message
            $receiver->ack($envelope);
        } catch (\Throwable $e) {
            // Reject the message on failure
            $receiver->reject($envelope);
            throw $e;
        }
    }

    private function getQueueCount(ReceiverInterface $receiver): int
    {
        // Most receivers don't support counting, so we return -1 to indicate unknown
        if (method_exists($receiver, 'getMessageCount')) {
            return $receiver->getMessageCount();
        }

        return -1;
    }
}
