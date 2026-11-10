<?php

declare(strict_types=1);

namespace Agent001\Assistant\Cron;

use Agent001\Assistant\Model\Sync\Scheduler;

class Nightly
{
    /**
     * @param Scheduler $scheduler
     */
    public function __construct(private readonly Scheduler $scheduler)
    {
    }

    /**
     * Queue the nightly synchronization when due.
     */
    public function execute(): void
    {
        $this->scheduler->queue(dueOnly: true);
    }
}
