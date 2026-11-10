<?php

declare(strict_types=1);

namespace Agent001\Assistant\Cron;

use Agent001\Assistant\Model\Sync\Worker;

class Work
{
    private const SECONDS = 50;

    /**
     * @param Worker $worker
     */
    public function __construct(private readonly Worker $worker)
    {
    }

    /**
     * Process queued synchronization jobs for up to 50 seconds.
     */
    public function execute(): void
    {
        $deadline = time() + self::SECONDS;
        do {
            $processed = $this->worker->run();
        } while ($processed > 0 && time() < $deadline);
    }
}
