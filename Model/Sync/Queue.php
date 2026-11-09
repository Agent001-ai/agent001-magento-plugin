<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Sync;

use Magento\Framework\App\ResourceConnection;

class Queue
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

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
        ];
        $db->insertOnDuplicate(
            $this->table(),
            $data,
            ['generation', 'revision', 'attempts', 'available_at']
        );
    }

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

    public function pending(int $limit): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from($this->table())
            ->where('available_at <= ?', time())->where('attempts < ?', 5)
            ->order('kind ASC')->order('available_at ASC')->limit($limit));
    }

    public function current(array $job): bool
    {
        $db = $this->resource->getConnection();
        return (bool) $db->fetchOne($db->select()
            ->from($this->table(), ['job_key'])
            ->where('job_key = ?', $job['job_key'])->where('revision = ?', $job['revision']));
    }

    public function finish(array $job): void
    {
        $this->resource->getConnection()->delete(
            $this->table(),
            ['job_key = ?' => $job['job_key'], 'revision = ?' => $job['revision']]
        );
    }

    public function defer(array $job, int $seconds, bool $failed = false): void
    {
        $this->resource->getConnection()->update(
            $this->table(),
            ['available_at' => time() + $seconds, 'attempts' => (int) $job['attempts'] + ($failed ? 1 : 0)],
            ['job_key = ?' => $job['job_key'], 'revision = ?' => $job['revision']]
        );
    }

    public function clear(int $storeId): void
    {
        $this->resource->getConnection()->delete(
            $this->table(),
            ['store_id = ?' => $storeId]
        );
    }

    public function retryFailed(): void
    {
        $this->resource->getConnection()->update(
            $this->table(),
            ['attempts' => 0, 'available_at' => time()],
            ['attempts >= ?' => 5]
        );
    }

    public function stalled(int $storeId, int $seconds = 120): bool
    {
        $db = $this->resource->getConnection();
        return (bool) $db->fetchOne($db->select()->from(
            $this->table(),
            ['job_key'],
        )->where('store_id = ?', $storeId)
            ->where('kind IN (?)', ['full', 'poll'])
            ->where('attempts < ?', 5)
            ->where('available_at < ?', time() - $seconds)
            ->limit(1));
    }

    public function failures(int $storeId): int
    {
        $db = $this->resource->getConnection();
        return (int) $db->fetchOne($db->select()->from(
            $this->table(),
            ['COUNT(*)'],
        )->where('store_id = ?', $storeId)->where('attempts >= ?', 5));
    }

    private function table(): string
    {
        return $this->resource->getTableName('agent001_job');
    }
}
