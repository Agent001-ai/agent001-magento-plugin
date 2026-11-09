<?php

declare(strict_types=1);

namespace Agent001\Assistant\Cron;

use Agent001\Assistant\Model\Sync\Scheduler;

class Nightly
{
    public function __construct(private readonly Scheduler $scheduler)
    {
    }

    public function execute(): void
    {
        $this->scheduler->queue(dueOnly: true);
    }
}
