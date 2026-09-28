<?php

namespace App;

use App\Message\ExpireUnpaidOrders;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Recurring tasks, run by the Messenger worker (transport scheduler_default).
 */
#[AsSchedule]
final readonly class Schedule implements ScheduleProviderInterface
{
    /**
     * Creates the schedule.
     */
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    /**
     * Returns the recurring tasks.
     */
    public function getSchedule(): SymfonySchedule
    {
        return new SymfonySchedule()
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
            ->add(RecurringMessage::every('5 minutes', new ExpireUnpaidOrders()));
    }
}
