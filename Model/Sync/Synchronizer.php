<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Sync;

use Agent001\Assistant\Model\Catalog\Categories;
use Agent001\Assistant\Model\Catalog\Products;
use Agent001\Assistant\Model\Catalog\StoreContext;
use Agent001\Assistant\Model\Client\Client;
use Agent001\Assistant\Model\Client\DTO\SessionDTO;
use Agent001\Assistant\Model\Settings;

class Synchronizer
{
    public const MAX_PRODUCTS = 50000;

    public const READ_SECONDS = 7200;

    private const INDEX_SECONDS = 86400;

    private const BATCH_BYTES = 7000000;

    private const BATCH_PRODUCTS = 500;

    private const CONTEXT_RECORDS = 50000;

    /**
     * @param Products $products
     * @param StoreContext $context
     * @param Categories $categories
     * @param Client $client
     * @param Settings $settings
     * @param Queue $queue
     */
    public function __construct(
        private readonly Products $products,
        private readonly StoreContext $context,
        private readonly Categories $categories,
        private readonly Client $client,
        private readonly Settings $settings,
        private readonly Queue $queue,
    ) {
    }

    /**
     * Export the full catalog of a store view.
     *
     * @param int $storeId
     * @param array $setting
     */
    public function full(int $storeId, array $setting): void
    {
        $this->categories->reset();
        $session = bin2hex(string: random_bytes(length: 16));
        $setting['session_id'] = $session;
        $setting['status'] = 'reading';
        $setting['count'] = 0;
        $setting['skipped'] = 0;
        $setting['started_at'] = time();
        $setting['uploaded_at'] = 0;
        $setting['message'] = '';
        $this->settings->saveCurrent(storeId: $storeId, data: $setting);
        $index = 0;
        $context = $this->storeContext(storeId: $storeId);
        if (!empty($context)) {
            $this->send(
                storeId: $storeId,
                setting: $setting,
                session: $session,
                index: $index,
                products: [],
                context: $context,
            );
        }
        $count = 0;
        $buffer = [];
        $bytes = 0;
        foreach ($this->products->read(storeId: $storeId, categoryIds: $setting['categories'] ?? []) as $product) {
            if (++$count > self::MAX_PRODUCTS) {
                throw new StoppedException(message: 'The catalog has more than 50,000 products.');
            }
            if (time() - $setting['started_at'] > self::READ_SECONDS) {
                throw new StoppedException(message: 'Reading the catalog took longer than 2 hours.');
            }
            $size = strlen(string: json_encode(value: $product, flags: Client::JSON_FLAGS));
            if ($size > self::BATCH_BYTES) {
                throw new StoppedException(message: 'A product exceeds the synchronization size limit.');
            }
            if (count(value: $buffer) >= self::BATCH_PRODUCTS || $bytes + $size > self::BATCH_BYTES) {
                $this->send(
                    storeId: $storeId,
                    setting: $setting,
                    session: $session,
                    index: $index,
                    products: $buffer,
                );
                $setting['count'] = $count - 1;
                $setting['skipped'] = $this->products->skipped();
                $this->settings->saveCurrent(storeId: $storeId, data: $setting);
                $buffer = [];
                $bytes = 0;
            }
            $buffer[] = $product;
            $bytes += $size;
        }
        $this->send(
            storeId: $storeId,
            setting: $setting,
            session: $session,
            index: $index,
            products: $buffer,
            last: true,
        );
        $setting['count'] = $count;
        $setting['skipped'] = $this->products->skipped();
        $setting['status'] = 'indexing';
        $setting['uploaded_at'] = time();
        $this->settings->saveCurrent(storeId: $storeId, data: $setting);
        $this->queue->enqueue(storeId: $storeId, generation: $setting['generation'], kind: 'poll', delay: 5);
    }

    /**
     * Check indexing status of the last export and return seconds until the next check, or 0 when done.
     *
     * @param int $storeId
     * @param array $setting
     */
    public function poll(int $storeId, array $setting): int
    {
        if (($setting['status'] ?? '') !== 'indexing') {
            return 0;
        }
        $waiting = time() - (int) ($setting['uploaded_at'] ?? $setting['started_at'] ?? 0);
        if ($waiting > self::INDEX_SECONDS) {
            throw new StoppedException(message: 'Agent001 did not finish indexing within 24 hours.');
        }
        $response = $this->client->request(
            method: 'GET',
            path: '/api/v1/ingest/sessions/' . rawurlencode(string: $setting['session_id']),
            apiKey: $setting['api_key'],
            channel: $setting['channel'],
        );
        $session = SessionDTO::from(record: $response);
        if ($session->failed()) {
            throw new StoppedException(
                message: empty($session->error) ? 'Agent001 could not index the catalog.' : $session->error,
            );
        }
        if (!$session->succeeded()) {
            return $waiting < 60 ? 5 : ($waiting < 600 ? 30 : 300);
        }
        $setting['status'] = 'success';
        $setting['setup_completed'] = true;
        $setting['last_sync_at'] = gmdate(format: 'c');
        $setting['message'] = '';
        $this->settings->saveCurrent(storeId: $storeId, data: $setting, invalidate: true);
        return 0;
    }

    /**
     * Send or delete a single product.
     *
     * @param int $storeId
     * @param int $productId
     * @param array $setting
     */
    public function product(int $storeId, int $productId, array $setting): void
    {
        $product = $this->products->read(
            storeId: $storeId,
            categoryIds: $setting['categories'] ?? [],
            onlyId: $productId,
        )->current();
        if ($product) {
            $this->client->request(
                method: 'PUT',
                path: '/api/v1/ingest/products/' . $productId,
                apiKey: $setting['api_key'],
                data: ['site' => $setting['site'], 'product' => $product],
                channel: $setting['channel'],
                attempts: 2,
                timeout: 15,
            );
            return;
        }
        $this->client->request(
            method: 'DELETE',
            path: '/api/v1/ingest/products/' . $productId,
            apiKey: $setting['api_key'],
            channel: $setting['channel'],
            attempts: 2,
            timeout: 15,
        );
    }

    /**
     * Return store context records that fit in the first batch.
     *
     * @param int $storeId
     * @return list<array<string,string>>
     */
    private function storeContext(int $storeId): array
    {
        $records = [];
        $bytes = 0;
        foreach ($this->context->read(storeId: $storeId) as $entry) {
            $size = strlen(string: json_encode(value: $entry, flags: Client::JSON_FLAGS));
            if (count(value: $records) >= self::CONTEXT_RECORDS || $bytes + $size > self::BATCH_BYTES) {
                break;
            }
            $records[] = $entry;
            $bytes += $size;
        }
        return $records;
    }

    /**
     * Send a batch of the export session unless the store view was changed meanwhile.
     *
     * @param int $storeId
     * @param array $setting
     * @param string $session
     * @param int $index
     * @param array $products
     * @param array $context
     * @param bool $last
     */
    private function send(
        int $storeId,
        array $setting,
        string $session,
        int &$index,
        array $products,
        array $context = [],
        bool $last = false,
    ): void {
        if ($index >= 1000) {
            throw new StoppedException(message: 'The catalog needs more than 1,000 batches.');
        }
        $current = $this->settings->get(storeId: $storeId);
        if (($current['generation'] ?? '') !== $setting['generation'] || empty($current['api_key'])) {
            throw new AbortedException(message: 'The store view settings changed.');
        }
        $this->client->request(method: 'POST', path: '/api/v1/ingest', apiKey: $setting['api_key'], data: [
            'site' => $setting['site'], 'timestamp' => gmdate(format: 'c'), 'version' => Client::VERSION,
            'session_id' => $session, 'batch_index' => $index, 'is_first' => $index === 0, 'is_last' => $last,
            'products' => $products, 'store_context' => $context,
        ], headers: [
            'X-Agent001-Batch' => $index . '/' . ($last ? 'final' : 'partial'),
            'X-Agent001-Session' => $session,
        ], channel: $setting['channel']);
        ++$index;
    }
}
