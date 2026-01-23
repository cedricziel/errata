<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Parquet\ParquetWriterService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Flushes the Parquet buffer on kernel terminate and after message handling.
 *
 * This ensures that any buffered events are written to Parquet files:
 * - After each messenger message is processed (for immediate consistency in testing/sync scenarios)
 * - When the kernel/worker process terminates (as a safety net)
 */
class ParquetFlushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ParquetWriterService $parquetWriter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['onTerminate', -100],
            WorkerMessageHandledEvent::class => ['onMessageHandled', -100],
        ];
    }

    public function onTerminate(): void
    {
        $this->parquetWriter->flush();
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->parquetWriter->flush();
    }
}
