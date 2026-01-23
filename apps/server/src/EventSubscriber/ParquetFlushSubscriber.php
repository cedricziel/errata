<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Parquet\ParquetWriterService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Flushes the Parquet buffer on kernel terminate.
 *
 * This ensures that any buffered events are written to Parquet files
 * when the kernel/worker process terminates (as a safety net).
 *
 * Note: The ProcessEventBatchHandler writes events directly to parquet,
 * so per-message flushing is no longer needed.
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
        ];
    }

    public function onTerminate(): void
    {
        $this->parquetWriter->flush();
    }
}
