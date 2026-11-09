<?php

declare(strict_types=1);

namespace Agent001\Assistant\Cron;

use Agent001\Assistant\Model\Sync\Worker;

class Work
{
    public function __construct(private readonly Worker $worker)
    {
    }

    public function execute(): void
    {
        $this->worker->run();
    }
}
