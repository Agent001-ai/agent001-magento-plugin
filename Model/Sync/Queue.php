<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Sync;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;

class Queue
{
    public const MAX_ATTEMPTS = 5;

    /**
     * @param ResourceConnection $resource
     * @param LockManagerInterface $locks
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LockManagerInterface $locks,
    ) {
    }

    /**
     * Return the lock name that serializes work on a store view.
     *
     * @param int $storeId
     */
    public function lockName(int $storeId): string
    {
        return 'agent001_store_' . $storeId;
    }

    /**
     * Queue a job or replace its revision.
     *
     * @param int $storeId
     * @param string $generation
     * @param string $kind
     * @param int $productId
     * @param int $delay
     */
    public function enqueue(
        int $storeId,
        string $generation,
        string $kind = 'full',
        int $productId = 0,
        int $delay = 0,
    ): void {
        $db = $this->resource->getConnection();
        $data = [
            'job_key' => $kind . '_' . $storeId . '_' . $productId, 'store_id' => $storeId,
            'kind' => $kind, 'product_id' => $productId, 'generation' => $generation,
            'revision' => bin2hex(string: random_bytes(length: 16)), 'attempts' => 0, 'available_at' => time() + $delay,
            'queued_at' => time(),
        ];
        $db->insertOnDuplicate(
            $this->table(),
            $data,
            ['generation', 'revision', 'attempts', 'available_at']
        );
    }

    /**
     * Queue an indexing poll unless one exists.
     *
     * @param int $storeId
     * @param string $generation
     */
    public function ensurePoll(int $storeId, string $generation): void
    {
        $db = $this->resource->getConnection();
        $exists = $db->fetchOne($db->select()
            ->from($this->table(), ['job_key'])
            ->where('job_key = ?', 'poll_' . $storeId . '_0'));
        if (!$exists) {
            $this->enqueue(storeId: $storeId, generation: $generation, kind: 'poll');
        }
    }

    /**
     * Return jobs ready to run.
     *
     * @param int $limit
     */
    public function pending(int $limit): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from($this->table())
            ->where('available_at <= ?', time())->where('attempts < ?', self::MAX_ATTEMPTS)
            ->order('kind ASC')->order('available_at ASC')->limit($limit));
    }

    /**
     * Check whether a job still has the same revision.
     *
     * @param array $job
     */
    public function current(array $job): bool
    {
        $db = $this->resource->getConnection();
        return (bool) $db->fetchOne($db->select()
            ->from($this->table(), ['job_key'])
            ->where('job_key = ?', $job['job_key'])->where('revision = ?', $job['revision']));
    }

    /**
     * Remove a completed job unless its revision changed.
     *
     * @param array $job
     */
    public function finish(array $job): void
    {
        $this->resource->getConnection()->delete(
            $this->table(),
            ['job_key = ?' => $job['job_key'], 'revision = ?' => $job['revision']]
        );
    }

    /**
     * Postpone a job.
     *
     * @param array $job
     * @param int $seconds
     * @param bool $failed
     */
    public function defer(array $job, int $seconds, bool $failed = false): void
    {
        $this->resource->getConnection()->update(
            $this->table(),
            ['available_at' => time() + $seconds, 'attempts' => (int) $job['attempts'] + ($failed ? 1 : 0)],
            ['job_key = ?' => $job['job_key'], 'revision = ?' => $job['revision']]
        );
    }

    /**
     * Remove queued jobs of a store view.
     *
     * @param int $storeId
     */
    public function clear(int $storeId): void
    {
        $this->resource->getConnection()->delete(
            $this->table(),
            ['store_id = ?' => $storeId]
        );
    }

    /**
     * Make failed jobs available again, optionally only for one store view.
     *
     * @param int|null $storeId
     */
    public function retryFailed(?int $storeId = null): int
    {
        $where = ['attempts >= ?' => self::MAX_ATTEMPTS];
        if ($storeId !== null) {
            $where['store_id = ?'] = $storeId;
        }
        return $this->resource->getConnection()->update(
            $this->table(),
            ['attempts' => 0, 'available_at' => time()],
            $where,
        );
    }

    /**
     * Check whether a synchronization job is overdue while no worker processes the store view.
     *
     * @param int $storeId
     * @param int $seconds
     */
    public function stalled(int $storeId, int $seconds = 120): bool
    {
        if ($this->locks->isLocked($this->lockName(storeId: $storeId))) {
            return false;
        }
        $db = $this->resource->getConnection();
        return (bool) $db->fetchOne($db->select()->from(
            $this->table(),
            ['job_key'],
        )->where('store_id = ?', $storeId)
            ->where('kind IN (?)', ['full', 'poll'])
            ->where('attempts < ?', self::MAX_ATTEMPTS)
            ->where('available_at < ?', time() - $seconds)
            ->limit(1));
    }

    /**
     * Count failed jobs of a store view.
     *
     * @param int $storeId
     */
    public function failures(int $storeId): int
    {
        $db = $this->resource->getConnection();
        return (int) $db->fetchOne($db->select()->from(
            $this->table(),
            ['COUNT(*)'],
        )->where('store_id = ?', $storeId)->where('attempts >= ?', self::MAX_ATTEMPTS));
    }

    /**
     * Return the queue table name.
     */
    private function table(): string
    {
        return $this->resource->getTableName('agent001_job');
    }
}
