<?php

declare(strict_types=1);

namespace Agent001\Assistant\Console\Command;

use Agent001\Assistant\Model\Settings;
use Agent001\Assistant\Model\Sync\Queue;
use Agent001\Assistant\Model\Sync\NightlyPolicy;
use DateTimeImmutable;
use DateTimeZone;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Sync extends Command
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Queue $queue,
        private readonly NightlyPolicy $policy,
        private readonly StoreManagerInterface $stores,
        private readonly TimezoneInterface $timezone,
        private readonly LockManagerInterface $locks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(name: 'agent001:sync')->setDescription(description: 'Queue full catalog synchronization.');
        $this->addOption(name: 'store', mode: InputOption::VALUE_REQUIRED, description: 'Store view code or ID.');
        $this->addOption(
            name: 'due',
            mode: InputOption::VALUE_NONE,
            description: 'Queue once per day in the 01:00–05:00 store-time window.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filter = $input->getOption(name: 'store');
        $storeFilter = $filter ? (int) $this->stores->getStore(storeId: $filter)->getId() : null;
        $count = 0;
        $dueOnly = (bool) $input->getOption(name: 'due');
        foreach ($this->settings->connected() as $storeId => $setting) {
            if (($storeFilter !== null && $storeFilter !== $storeId) || empty($setting['setup_completed'])) {
                continue;
            }
            $lock = 'agent001_store_' . $storeId;
            if (!$this->locks->lock(name: $lock, timeout: 0)) {
                continue;
            }
            try {
                $setting = $this->settings->get(storeId: $storeId);
                $zone = $this->timezone->getConfigTimezone(scopeType: 'store', scopeCode: $storeId);
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
                $this->locks->unlock(name: $lock);
            }
        }
        $output->writeln(messages: (string) $count . ' store views queued. Run agent001:work to process them.');
        return Command::SUCCESS;
    }
}
