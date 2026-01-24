<?php

declare(strict_types=1);

namespace App;

use App\Message\CompactParquet;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');

        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run

            // Compact today's files every minute (catch recent writes)
            ->add(RecurringMessage::cron('* * * * *', new CompactParquet($today)))

            // Compact yesterday's files every 10 minutes (catch stragglers)
            ->add(RecurringMessage::cron('*/10 * * * *', new CompactParquet($yesterday)))
        ;
    }
}
