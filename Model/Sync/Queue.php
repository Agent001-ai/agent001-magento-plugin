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
            table: $this->table(),
            data: $data,
            fields: ['generation', 'revision', 'attempts', 'available_at']
        );
    }

    public function ensurePoll(int $storeId, string $generation): void
    {
        $db = $this->resource->getConnection();
        $exists = $db->fetchOne(sql: $db->select()
            ->from(name: $this->table(), cols: ['job_key'])
            ->where(cond: 'job_key = ?', value: 'poll_' . $storeId . '_0'));
        if (!$exists) {
            $this->enqueue(storeId: $storeId, generation: $generation, kind: 'poll');
        }
    }

    public function pending(int $limit): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll(sql: $db->select()->from(name: $this->table())
            ->where(cond: 'available_at <= ?', value: time())->where(cond: 'attempts < ?', value: 5)
            ->order(spec: 'kind ASC')->order(spec: 'available_at ASC')->limit(count: $limit));
    }

    public function current(array $job): bool
    {
        $db = $this->resource->getConnection();
        return (bool) $db->fetchOne(sql: $db->select()
            ->from(name: $this->table(), cols: ['job_key'])
            ->where(cond: 'job_key = ?', value: $job['job_key'])->where(cond: 'revision = ?', value: $job['revision']));
    }

    public function finish(array $job): void
    {
        $this->resource->getConnection()->delete(
            table: $this->table(),
            where: ['job_key = ?' => $job['job_key'], 'revision = ?' => $job['revision']]
        );
    }

    public function defer(array $job, int $seconds, bool $failed = false): void
    {
        $this->resource->getConnection()->update(
            table: $this->table(),
            bind: ['available_at' => time() + $seconds, 'attempts' => (int) $job['attempts'] + ($failed ? 1 : 0)],
            where: ['job_key = ?' => $job['job_key'], 'revision = ?' => $job['revision']]
        );
    }

    public function clear(int $storeId): void
    {
        $this->resource->getConnection()->delete(
            table: $this->table(),
            where: ['store_id = ?' => $storeId]
        );
    }

    public function retryFailed(): void
    {
        $this->resource->getConnection()->update(
            table: $this->table(),
            bind: ['attempts' => 0, 'available_at' => time()],
            where: ['attempts >= ?' => 5]
        );
    }

    public function stalled(int $storeId, int $seconds = 120): bool
    {
        $db = $this->resource->getConnection();
        return (bool) $db->fetchOne(sql: $db->select()->from(
            name: $this->table(),
            cols: ['job_key'],
        )->where(cond: 'store_id = ?', value: $storeId)
            ->where(cond: 'kind IN (?)', value: ['full', 'poll'])
            ->where(cond: 'attempts < ?', value: 5)
            ->where(cond: 'available_at < ?', value: time() - $seconds)
            ->limit(count: 1));
    }

    public function failures(int $storeId): int
    {
        $db = $this->resource->getConnection();
        return (int) $db->fetchOne(sql: $db->select()->from(
            name: $this->table(),
            cols: ['COUNT(*)'],
        )->where(cond: 'store_id = ?', value: $storeId)->where(cond: 'attempts >= ?', value: 5));
    }

    private function table(): string
    {
        return $this->resource->getTableName(modelEntity: 'agent001_job');
    }
}
