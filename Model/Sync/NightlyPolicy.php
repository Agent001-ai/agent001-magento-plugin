<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Sync;

use DateTimeImmutable;

class NightlyPolicy
{
    public function isDue(string $site, DateTimeImmutable $now, ?string $lastDate): bool
    {
        $hour = 1 + (int) sprintf('%u', crc32(string: $site)) % 4;
        return (int) $now->format(format: 'G') === $hour && $lastDate !== $now->format(format: 'Y-m-d');
    }
}
