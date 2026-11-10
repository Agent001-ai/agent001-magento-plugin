<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model;

use Agent001\Assistant\Model\Sync\AbortedException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

class Settings
{
    public const INSTALLATION = 0;

    public const SECRETS = ['api_key', 'widget_key'];

    /**
     * @param ResourceConnection $resource
     * @param EncryptorInterface $encryptor
     * @param ManagerInterface $events
     * @param StoreManagerInterface $stores
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EncryptorInterface $encryptor,
        private readonly ManagerInterface $events,
        private readonly StoreManagerInterface $stores,
    ) {
    }

    /**
     * Return settings of a store view with the installation API key.
     *
     * @param int $storeId
     */
    public function get(int $storeId): array
    {
        $data = $this->row(storeId: $storeId);
        if ($storeId === self::INSTALLATION) {
            return $data;
        }
        return ['api_key' => $this->apiKey()] + $data;
    }

    /**
     * Return the installation API key.
     */
    public function apiKey(): string
    {
        return (string) ($this->row(storeId: self::INSTALLATION)['api_key'] ?? '');
    }

    /**
     * Encrypt and store settings of a store view.
     *
     * @param int $storeId
     * @param array $data
     * @param bool $invalidate
     */
    public function save(int $storeId, array $data, bool $invalidate = false): void
    {
        if ($storeId !== self::INSTALLATION) {
            unset($data['api_key']);
        }
        $this->resource->getConnection()->insertOnDuplicate(
            $this->table(),
            ['store_id' => $storeId, 'data' => $this->encode(data: $data)],
            ['data'],
        );
        if ($invalidate) {
            $this->invalidate(storeId: $storeId);
        }
    }

    /**
     * Store settings of a store view unless its generation changed since they were read.
     *
     * @param int $storeId
     * @param array $data
     * @param bool $invalidate
     */
    public function saveCurrent(int $storeId, array $data, bool $invalidate = false): void
    {
        unset($data['api_key']);
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $stored = $db->fetchOne($db->select()->from($this->table(), ['data'])
                ->where('store_id = ?', $storeId)->forUpdate());
            $current = $stored ? json_decode(json: (string) $stored, associative: true) : [];
            if (!is_array(value: $current) || ($current['generation'] ?? '') !== ($data['generation'] ?? null)) {
                throw new AbortedException(message: 'The store view settings changed.');
            }
            $db->update($this->table(), ['data' => $this->encode(data: $data)], ['store_id = ?' => $storeId]);
            $db->commit();
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
        if ($invalidate) {
            $this->invalidate(storeId: $storeId);
        }
    }

    /**
     * Return identifiers of configured store views.
     */
    public function storeIds(): array
    {
        $db = $this->resource->getConnection();
        $ids = $db->fetchCol($db->select()->from($this->table(), ['store_id'])
            ->where('store_id <> ?', self::INSTALLATION));
        return array_map(callback: 'intval', array: $ids);
    }

    /**
     * Return settings of active store views with the installation API key.
     */
    public function connected(): array
    {
        $apiKey = $this->apiKey();
        if (empty($apiKey)) {
            return [];
        }
        $active = [];
        foreach ($this->stores->getStores() as $store) {
            if ($store->isActive()) {
                $active[(int) $store->getId()] = true;
            }
        }
        $settings = [];
        foreach ($this->storeIds() as $id) {
            if (isset($active[$id])) {
                $settings[$id] = ['api_key' => $apiKey] + $this->row(storeId: $id);
            }
        }
        return $settings;
    }

    /**
     * Read and decrypt a settings row.
     *
     * @param int $storeId
     */
    private function row(int $storeId): array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from($this->table())->where('store_id = ?', $storeId);
        $row = $db->fetchRow($select);
        if (!$row) {
            return [];
        }
        $data = json_decode(json: $row['data'], associative: true, flags: JSON_THROW_ON_ERROR);
        foreach (self::SECRETS as $key) {
            $data[$key] = !empty($data[$key]) ? $this->encryptor->decrypt($data[$key]) : '';
        }
        return $data;
    }

    /**
     * Encrypt secrets and encode settings as JSON.
     *
     * @param array $data
     */
    private function encode(array $data): string
    {
        foreach (self::SECRETS as $key) {
            if (array_key_exists(key: $key, array: $data)) {
                $data[$key] = !empty($data[$key]) ? $this->encryptor->encrypt($data[$key]) : '';
            }
        }
        return json_encode(value: $data, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Clean storefront cache of a store view.
     *
     * @param int $storeId
     */
    private function invalidate(int $storeId): void
    {
        $this->events->dispatch('clean_cache_by_tags', ['object' => new CacheIdentity(storeId: $storeId)]);
    }

    /**
     * Return the settings table name.
     */
    private function table(): string
    {
        return $this->resource->getTableName('agent001_setting');
    }
}
