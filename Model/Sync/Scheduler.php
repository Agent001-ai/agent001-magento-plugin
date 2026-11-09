<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Sync;

use Agent001\Assistant\Model\Settings;
use DateTimeImmutable;
use DateTimeZone;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

class Scheduler
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Queue $queue,
        private readonly NightlyPolicy $policy,
        private readonly TimezoneInterface $timezone,
        private readonly LockManagerInterface $locks,
    ) {
    }

    public function queue(?int $storeFilter = null, bool $dueOnly = false): int
    {
        $count = 0;
        foreach ($this->settings->connected() as $storeId => $setting) {
            if (($storeFilter !== null && $storeFilter !== $storeId) || empty($setting['setup_completed'])) {
                continue;
            }
            $lock = 'agent001_store_' . $storeId;
            if (!$this->locks->lock($lock, 0)) {
                continue;
            }
            try {
                $setting = $this->settings->get(storeId: $storeId);
                $zone = $this->timezone->getConfigTimezone('store', $storeId);
                $now = new DateTimeImmutable(timezone: new DateTimeZone(timezone: $zone));
                $lastDate = $setting['nightly_date'] ?? null;
                if ($dueOnly && !$this->policy->isDue(site: $setting['site'], now: $now, lastDate: $lastDate)) {
                    continue;
                }
                $this->queue->enqueue(storeId: $storeId, generation: $setting['generation']);
                if ($dueOnly) {
                    $setting['nightly_date'] = $now->format(format: 'Y-m-d');
                }
                if (($setting['status'] ?? '') !== 'indexing') {
                    $setting['status'] = 'queued';
                }
                $this->settings->save(storeId: $storeId, data: $setting);
                ++$count;
            } finally {
                $this->locks->unlock($lock);
            }
        }
        return $count;
    }
}
