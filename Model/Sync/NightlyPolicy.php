<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Sync;

use DateTimeImmutable;

class NightlyPolicy
{
    private const WINDOW_END = 6;

    /**
     * Check whether the nightly synchronization is due in the night window of the store.
     *
     * @param string $site
     * @param DateTimeImmutable $now
     * @param string|null $lastDate
     */
    public function isDue(string $site, DateTimeImmutable $now, ?string $lastDate): bool
    {
        $start = 1 + (int) sprintf('%u', crc32(string: $site)) % 4;
        $hour = (int) $now->format(format: 'G');
        return $hour >= $start && $hour < self::WINDOW_END && $lastDate !== $now->format(format: 'Y-m-d');
    }
}
