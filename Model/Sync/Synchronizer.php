<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Sync;

use Agent001\Assistant\Model\Catalog\Products;
use Agent001\Assistant\Model\Catalog\StoreContext;
use Agent001\Assistant\Model\Client\Client;
use Agent001\Assistant\Model\Settings;
use Agent001\Assistant\Model\Client\DTO\SessionDTO;
use RuntimeException;

class Synchronizer
{
    private const BATCH_BYTES = 7000000;

    public function __construct(
        private readonly Products $products,
        private readonly StoreContext $context,
        private readonly Client $client,
        private readonly Settings $settings,
        private readonly Queue $queue,
    ) {
    }

    public function full(int $storeId, array $setting): void
    {
        $session = bin2hex(string: random_bytes(length: 16));
        $setting['session_id'] = $session;
        $setting['status'] = 'reading';
        $setting['count'] = 0;
        $setting['started_at'] = time();
        $setting['message'] = '';
        $this->settings->save(storeId: $storeId, data: $setting);
        $index = 0;
        $count = 0;
        $buffer = [];
        $bytes = 0;
        foreach ($this->context->read(storeId: $storeId) as $entry) {
            $size = strlen(string: json_encode(value: $entry, flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            if (!empty($buffer) && $bytes + $size > self::BATCH_BYTES) {
                $this->send(setting: $setting, session: $session, index: $index, products: [], context: $buffer);
                $buffer = [];
                $bytes = 0;
            }
            $buffer[] = $entry;
            $bytes += $size;
        }
        if (!empty($buffer)) {
            $this->send(setting: $setting, session: $session, index: $index, products: [], context: $buffer);
        }
        $buffer = [];
        $bytes = 0;
        foreach ($this->products->read(storeId: $storeId, categoryIds: $setting['categories'] ?? []) as $product) {
            if (++$count > 50000 || time() - $setting['started_at'] > 7200) {
                throw new RuntimeException(message: 'Catalog synchronization limit exceeded.');
            }
            $size = strlen(string: json_encode(value: $product, flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            if ($size > self::BATCH_BYTES) {
                throw new RuntimeException(message: 'A product exceeds the synchronization size limit.');
            }
            if (count(value: $buffer) >= 500 || $bytes + $size > self::BATCH_BYTES) {
                $this->send(setting: $setting, session: $session, index: $index, products: $buffer);
                $setting['count'] = $count - 1;
                $this->settings->save(storeId: $storeId, data: $setting);
                $buffer = [];
                $bytes = 0;
            }
            $buffer[] = $product;
            $bytes += $size;
        }
        $this->send(setting: $setting, session: $session, index: $index, products: $buffer, last: true);
        $setting['count'] = $count;
        $setting['status'] = 'indexing';
        $setting['last_sync_at'] = gmdate(format: 'c');
        $setting['checked_at'] = 0;
        $this->settings->save(storeId: $storeId, data: $setting);
        $this->queue->enqueue(storeId: $storeId, generation: $setting['generation'], kind: 'poll', delay: 5);
    }

    public function poll(int $storeId, array $setting): bool
    {
        if (($setting['status'] ?? '') !== 'indexing') {
            return true;
        }
        if (time() - (int) $setting['started_at'] > 7200) {
            throw new RuntimeException(message: 'Indexing timed out.');
        }
        $response = $this->client->request(
            method: 'GET',
            path: '/api/v1/ingest/sessions/' . rawurlencode(string: $setting['session_id']),
            apiKey: $setting['api_key'],
            channel: $setting['channel'],
        );
        $status = SessionDTO::from(record: $response)->status;
        if ($status === 'failed') {
            throw new RuntimeException(message: 'Backend indexing failed.');
        }
        if ($status !== 'succeeded') {
            return false;
        }
        $setting['status'] = 'success';
        $setting['setup_completed'] = true;
        $setting['last_sync_at'] = gmdate(format: 'c');
        $this->settings->save(storeId: $storeId, data: $setting, invalidate: true);
        return true;
    }

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
            );
            return;
        }
        try {
            $this->client->request(
                method: 'DELETE',
                path: '/api/v1/ingest/products/' . $productId,
                apiKey: $setting['api_key'],
                channel: $setting['channel'],
            );
        } catch (RuntimeException $error) {
            if ($error->getCode() !== 404) {
                throw $error;
            }
        }
    }

    private function send(
        array $setting,
        string $session,
        int &$index,
        array $products,
        array $context = [],
        bool $last = false,
    ): void {
        if ($index >= 1000) {
            throw new RuntimeException(message: 'Too many catalog batches.');
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
