<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface;

class Settings
{
    public const INSTALLATION = 0;

    public const SECRETS = ['api_key', 'widget_key'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EncryptorInterface $encryptor,
        private readonly ManagerInterface $events,
    ) {
    }

    public function get(int $storeId): array
    {
        $data = $this->row(storeId: $storeId);
        if ($storeId === self::INSTALLATION) {
            return $data;
        }
        return ['api_key' => $this->apiKey()] + $data;
    }

    public function apiKey(): string
    {
        return (string) ($this->row(storeId: self::INSTALLATION)['api_key'] ?? '');
    }

    public function save(int $storeId, array $data, bool $invalidate = false): void
    {
        if ($storeId !== self::INSTALLATION) {
            unset($data['api_key']);
        }
        foreach (self::SECRETS as $key) {
            if (array_key_exists(key: $key, array: $data)) {
                $data[$key] = !empty($data[$key]) ? $this->encryptor->encrypt(data: $data[$key]) : '';
            }
        }
        $this->resource->getConnection()->insertOnDuplicate(
            table: $this->table(),
            data: ['store_id' => $storeId, 'data' => json_encode(value: $data, flags: JSON_THROW_ON_ERROR)],
            fields: ['data'],
        );
        if ($invalidate) {
            $this->events->dispatch(
                eventName: 'clean_cache_by_tags',
                data: ['object' => new CacheIdentity(storeId: $storeId)],
            );
        }
    }

    public function storeIds(): array
    {
        $db = $this->resource->getConnection();
        $ids = $db->fetchCol(sql: $db->select()->from(name: $this->table(), cols: ['store_id'])
            ->where(cond: 'store_id <> ?', value: self::INSTALLATION));
        return array_map(callback: 'intval', array: $ids);
    }

    public function connected(): array
    {
        $apiKey = $this->apiKey();
        if (empty($apiKey)) {
            return [];
        }
        $settings = [];
        foreach ($this->storeIds() as $id) {
            $settings[$id] = ['api_key' => $apiKey] + $this->row(storeId: $id);
        }
        return $settings;
    }

    private function row(int $storeId): array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from(name: $this->table())->where(cond: 'store_id = ?', value: $storeId);
        $row = $db->fetchRow(sql: $select);
        if (!$row) {
            return [];
        }
        $data = json_decode(json: $row['data'], associative: true, flags: JSON_THROW_ON_ERROR);
        foreach (self::SECRETS as $key) {
            $data[$key] = !empty($data[$key]) ? $this->encryptor->decrypt(data: $data[$key]) : '';
        }
        return $data;
    }

    private function table(): string
    {
        return $this->resource->getTableName(modelEntity: 'agent001_setting');
    }
}
