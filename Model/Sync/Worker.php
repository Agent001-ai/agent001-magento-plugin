<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Sync;

use Agent001\Assistant\Model\Catalog\Stock;
use Agent001\Assistant\Model\Client\UnauthorizedException;
use Agent001\Assistant\Model\Config;
use Agent001\Assistant\Model\Settings;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class Worker
{
    public const STORE_URL_CHANGED = 'The store URL changed. Click "Update store URL" in the Agent001 settings.';

    public const KEY_REVOKED = 'The Agent001 key was revoked. Disconnect and connect the store again.';

    private const RUNNING = ['queued', 'reading'];

    private const STOCK_WAIT = 900;

    /**
     * @param Queue $queue
     * @param Settings $settings
     * @param Synchronizer $sync
     * @param Config $config
     * @param LockManagerInterface $locks
     * @param Emulation $emulation
     * @param LoggerInterface $logger
     * @param Stock $stock
     */
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

    /**
     * Process available queue jobs.
     *
     * @param int $limit
     */
    public function run(int $limit = 20): int
    {
        $processed = 0;
        foreach ($this->queue->pending(limit: $limit) as $job) {
            $storeId = (int) $job['store_id'];
            $lock = $this->queue->lockName(storeId: $storeId);
            if (!$this->locks->lock($lock, 0)) {
                continue;
            }
            try {
                if ($this->process(job: $job, storeId: $storeId)) {
                    ++$processed;
                }
            } catch (AbortedException) {
                $this->queue->finish(job: $job);
            } catch (StoppedException | UnauthorizedException $error) {
                $this->stop(job: $job, storeId: $storeId, error: $error);
            } catch (Throwable $error) {
                $this->retry(job: $job, storeId: $storeId, error: $error);
            } finally {
                $this->stock->reset();
                $this->locks->unlock($lock);
            }
        }
        return $processed;
    }

    /**
     * Run one job and return whether it did any work.
     *
     * @param array $job
     * @param int $storeId
     */
    private function process(array $job, int $storeId): bool
    {
        if (!$this->queue->current(job: $job)) {
            return false;
        }
        $setting = $this->settings->get(storeId: $storeId);
        if (empty($setting['api_key']) || empty($setting['agent_id']) || empty($setting['channel'])
            || ($setting['generation'] ?? '') !== $job['generation'] || !$this->config->active(storeId: $storeId)) {
            $this->queue->finish(job: $job);
            return false;
        }
        if ($this->config->site(storeId: $storeId) !== $setting['site']) {
            throw new StoppedException(message: self::STORE_URL_CHANGED);
        }
        $kind = $job['kind'];
        $status = $setting['status'] ?? '';
        if ($kind !== 'poll' && $status === 'indexing') {
            $this->queue->ensurePoll(storeId: $storeId, generation: $setting['generation']);
            $this->queue->defer(job: $job, seconds: 30);
            return false;
        }
        if ($kind === 'product') {
            if (in_array(needle: $status, haystack: self::RUNNING, strict: true)) {
                $this->queue->defer(job: $job, seconds: 60);
                return false;
            }
            if (empty($setting['setup_completed'])) {
                $this->queue->finish(job: $job);
                return false;
            }
            $waited = time() - (int) ($job['queued_at'] ?? 0);
            if ($waited < self::STOCK_WAIT && $this->stock->indexing()) {
                $this->queue->defer(job: $job, seconds: 60);
                return false;
            }
        }
        $this->emulation->startEnvironmentEmulation($storeId, 'frontend', true);
        try {
            $delay = 0;
            if ($kind === 'full') {
                $this->sync->full(storeId: $storeId, setting: $setting);
            } elseif ($kind === 'poll') {
                $delay = $this->sync->poll(storeId: $storeId, setting: $setting);
            } else {
                $this->sync->product(storeId: $storeId, productId: (int) $job['product_id'], setting: $setting);
            }
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
        if ($delay > 0) {
            $this->queue->defer(job: $job, seconds: $delay);
        } else {
            $this->queue->finish(job: $job);
        }
        return true;
    }

    /**
     * Drop a job that cannot succeed and show the reason in the settings.
     *
     * @param array $job
     * @param int $storeId
     * @param RuntimeException $error
     */
    private function stop(array $job, int $storeId, RuntimeException $error): void
    {
        $this->logger->error('Agent001 background operation stopped.', [
            'store_id' => $storeId, 'kind' => $job['kind'], 'exception_class' => $error::class,
            'message' => $error->getMessage(),
        ]);
        $this->queue->finish(job: $job);
        $unauthorized = $error instanceof UnauthorizedException;
        if ($job['kind'] === 'product' && !$unauthorized) {
            return;
        }
        $this->status(
            storeId: $storeId,
            status: 'failed',
            message: $unauthorized ? self::KEY_REVOKED : $error->getMessage(),
        );
    }

    /**
     * Postpone a failed job with exponential backoff.
     *
     * @param array $job
     * @param int $storeId
     * @param Throwable $error
     */
    private function retry(array $job, int $storeId, Throwable $error): void
    {
        $this->logger->error('Agent001 background operation failed.', [
            'store_id' => $storeId, 'kind' => $job['kind'], 'exception_class' => $error::class,
            'message' => $error->getMessage(),
        ]);
        $attempts = (int) $job['attempts'];
        $this->queue->defer(job: $job, seconds: min(3600, 30 * (2 ** $attempts)), failed: true);
        if ($job['kind'] === 'product') {
            return;
        }
        $terminal = $attempts + 1 >= Queue::MAX_ATTEMPTS;
        $this->status(
            storeId: $storeId,
            status: $terminal ? 'failed' : ($job['kind'] === 'poll' ? 'indexing' : 'queued'),
            message: $terminal
                ? 'Synchronization failed. Start it again after checking the connection.'
                : 'Synchronization will be retried.',
        );
    }

    /**
     * Store the synchronization status of a store view unless its settings changed.
     *
     * @param int $storeId
     * @param string $status
     * @param string $message
     */
    private function status(int $storeId, string $status, string $message): void
    {
        $setting = $this->settings->get(storeId: $storeId);
        if (empty($setting['generation'])) {
            return;
        }
        $setting['status'] = $status;
        $setting['message'] = $message;
        try {
            $this->settings->saveCurrent(storeId: $storeId, data: $setting);
        } catch (AbortedException) {
            return;
        }
    }
}
