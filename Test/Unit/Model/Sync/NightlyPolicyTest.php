<?php

declare(strict_types=1);

namespace Agent001\Assistant\Test\Unit\Model\Sync;

use Agent001\Assistant\Model\Sync\NightlyPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class NightlyPolicyTest extends TestCase
{
    private const SITE = 'https://shop.example.com';

    /**
     * Check the nightly window for hours around it and for a run already done today.
     */
    public function testWindow(): void
    {
        $policy = new NightlyPolicy();
        $start = 1 + (int) sprintf('%u', crc32(string: self::SITE)) % 4;
        $at = static fn (int $hour): DateTimeImmutable => new DateTimeImmutable(
            datetime: sprintf('2026-11-10 %02d:30:00', $hour),
        );

        $this->assertFalse($policy->isDue(site: self::SITE, now: $at($start - 1), lastDate: null));
        $this->assertTrue($policy->isDue(site: self::SITE, now: $at($start), lastDate: '2026-11-09'));
        $this->assertTrue($policy->isDue(site: self::SITE, now: $at(5), lastDate: null));
        $this->assertFalse($policy->isDue(site: self::SITE, now: $at(6), lastDate: null));
        $this->assertFalse($policy->isDue(site: self::SITE, now: $at(5), lastDate: '2026-11-10'));
    }
}
