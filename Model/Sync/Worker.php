<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Sync;

use Agent001\Assistant\Model\Catalog\Stock;
use Agent001\Assistant\Model\Config;
use Agent001\Assistant\Model\Settings;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class Worker
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Settings $settings,
        private readonly Synchronizer $sync,
        private readonly Config $config,
        private readonly LockManagerInterface $locks,
        private readonly Emulation $emulation,
        private readonly LoggerInterface $logger,
        private readonly Stock $stock,
    ) {
    }

    public function run(int $limit = 20): int
    {
        $processed = 0;
        foreach ($this->queue->pending(limit: $limit) as $job) {
            $storeId = (int) $job['store_id'];
            $lock = 'agent001_store_' . $storeId;
            if (!$this->locks->lock(name: $lock, timeout: 0)) {
                continue;
            }
            try {
                if (!$this->queue->current(job: $job)) {
                    continue;
                }
                $setting = $this->settings->get(storeId: $storeId);
                if (empty($setting['api_key']) || empty($setting['agent_id']) || empty($setting['channel'])
                    || ($setting['generation'] ?? '') !== $job['generation']) {
                    $this->queue->finish(job: $job);
                    continue;
                }
                if ($this->config->site(storeId: $storeId) !== $setting['site']) {
                    throw new RuntimeException(message: 'Store URL changed. Reconnect Agent001.');
                }
                if ($job['kind'] !== 'poll' && ($setting['status'] ?? '') === 'indexing') {
                    $this->queue->ensurePoll(storeId: $storeId, generation: $setting['generation']);
                    $this->queue->defer(job: $job, seconds: 30);
                    continue;
                }
                if ($job['kind'] === 'product' && empty($setting['setup_completed'])) {
                    $this->queue->finish(job: $job);
                    continue;
                }
                if ($job['kind'] === 'product' && $this->stock->indexing()) {
                    $this->queue->defer(job: $job, seconds: 60);
                    continue;
                }
                $this->emulation->startEnvironmentEmulation(storeId: $storeId, area: 'frontend', force: true);
                try {
                    $done = true;
                    if ($job['kind'] === 'full') {
                        $this->sync->full(storeId: $storeId, setting: $setting);
                    } elseif ($job['kind'] === 'poll') {
                        $done = $this->sync->poll(storeId: $storeId, setting: $setting);
                    } else {
                        $this->sync->product(storeId: $storeId, productId: (int) $job['product_id'], setting: $setting);
                    }
                } finally {
                    $this->emulation->stopEnvironmentEmulation();
                }
                if ($done) {
                    $this->queue->finish(job: $job);
                } else {
                    $this->queue->defer(job: $job, seconds: 5);
                }
                ++$processed;
            } catch (Throwable $error) {
                $this->logger->error(message: 'Agent001 background operation failed.', context: [
                    'store_id' => $storeId, 'kind' => $job['kind'], 'exception_class' => $error::class,
                ]);
                $this->queue->defer(job: $job, seconds: min(3600, 30 * (2 ** (int) $job['attempts'])), failed: true);
                if ($job['kind'] !== 'product') {
                    $setting = $this->settings->get(storeId: $storeId);
                    $terminal = (int) $job['attempts'] >= 4;
                    $setting['status'] = $terminal ? 'failed' : ($job['kind'] === 'poll' ? 'indexing' : 'queued');
                    $setting['message'] = $terminal
                        ? 'Synchronization failed. Start it again after checking the connection.'
                        : 'Synchronization will be retried.';
                    $this->settings->save(storeId: $storeId, data: $setting);
                }
            } finally {
                $this->locks->unlock(name: $lock);
            }
        }
        return $processed;
    }
}
