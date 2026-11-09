<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Connect;

use Agent001\Assistant\Model\Client\Client;
use Agent001\Assistant\Model\Client\DTO\ConnectionDTO;
use Agent001\Assistant\Model\Config;
use Agent001\Assistant\Model\Settings;
use Agent001\Assistant\Model\Sync\Queue;
use Magento\Framework\Lock\LockManagerInterface;
use RuntimeException;

class Connection
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Config $config,
        private readonly NonceStore $nonces,
        private readonly Client $client,
        private readonly Queue $queue,
        private readonly LockManagerInterface $locks,
    ) {
    }

    public function start(int $storeId, string $settingsUrl): string
    {
        if (!$this->locks->lock(name: 'agent001_connect', timeout: 0)) {
            throw new RuntimeException(message: 'Another connection is in progress.');
        }
        try {
            $site = $this->config->defaultSite();
            $installation = $this->settings->get(storeId: Settings::INSTALLATION);
            $installation['connect_revision'] = bin2hex(string: random_bytes(length: 16));
            $this->settings->save(storeId: Settings::INSTALLATION, data: $installation);
            $state = $this->nonces->create(data: [
                'store_id' => $storeId, 'site' => $site, 'settings_url' => $settingsUrl,
                'revision' => $installation['connect_revision'],
            ]);
            return $this->config->baseUrl() . '/connect?' . http_build_query(data: [
                'site' => $site, 'return_to' => $site . '/agent001/connect/callback', 'state' => $state,
                'channel' => $this->config->channel(storeId: $storeId), 'platform' => 'magento',
            ]);
        } finally {
            $this->locks->unlock(name: 'agent001_connect');
        }
    }

    public function complete(array $state, string $code): void
    {
        if (!$this->locks->lock(name: 'agent001_connect', timeout: 0)) {
            throw new RuntimeException(message: 'Another connection is in progress.');
        }
        try {
            $installation = $this->settings->get(storeId: Settings::INSTALLATION);
            if (($installation['connect_revision'] ?? '') !== $state['revision']
                || $this->config->defaultSite() !== $state['site']) {
                throw new RuntimeException(message: 'Connection state has changed.');
            }
            $response = $this->client->request(method: 'POST', path: '/connect/exchange', data: ['code' => $code]);
            $response = ConnectionDTO::from(record: $response);
            $this->settings->save(storeId: Settings::INSTALLATION, data: [
                'api_key' => $response->apiKey, 'account_name' => $response->accountName, 'site' => $state['site'],
            ]);
            $storeId = (int) $state['store_id'];
            if (empty($this->settings->get(storeId: $storeId)['channel'])) {
                $this->open(storeId: $storeId);
            }
        } finally {
            $this->locks->unlock(name: 'agent001_connect');
        }
    }

    public function open(int $storeId): void
    {
        $setting = $this->settings->get(storeId: $storeId);
        $this->queue->clear(storeId: $storeId);
        $setting['site'] = $this->config->site(storeId: $storeId);
        $setting['channel'] = $this->config->channel(storeId: $storeId);
        $setting['widget_key'] = bin2hex(string: random_bytes(length: 32));
        $setting['generation'] = bin2hex(string: random_bytes(length: 16));
        $setting['setup_completed'] = false;
        $setting['status'] = 'idle';
        unset($setting['agent_id'], $setting['embed_token'], $setting['active']);
        $this->settings->save(storeId: $storeId, data: $setting, invalidate: true);
    }

    public function disconnect(int $storeId): void
    {
        $setting = $this->settings->get(storeId: $storeId);
        if (!empty($setting['api_key']) && !empty($setting['channel'])) {
            $this->tolerate(
                method: 'DELETE',
                path: '/api/v1/integrations/me/catalogs/' . rawurlencode(string: $setting['channel']),
                apiKey: $setting['api_key'],
            );
        }
        $this->reset(storeId: $storeId);
    }

    public function disconnectAll(): void
    {
        $installation = $this->settings->get(storeId: Settings::INSTALLATION);
        if (!empty($installation['api_key'])) {
            $this->tolerate(method: 'DELETE', path: '/api/v1/integrations/me', apiKey: $installation['api_key']);
        }
        foreach ($this->settings->storeIds() as $storeId) {
            $this->reset(storeId: $storeId);
        }
        $this->settings->save(storeId: Settings::INSTALLATION, data: []);
    }

    private function reset(int $storeId): void
    {
        $this->queue->clear(storeId: $storeId);
        $this->settings->save(storeId: $storeId, data: [
            'widget_key' => bin2hex(string: random_bytes(length: 32)),
            'generation' => bin2hex(string: random_bytes(length: 16)),
        ], invalidate: true);
    }

    private function tolerate(string $method, string $path, string $apiKey): void
    {
        try {
            $this->client->request(method: $method, path: $path, apiKey: $apiKey);
        } catch (RuntimeException $error) {
            if (!in_array(needle: $error->getCode(), haystack: [401, 404], strict: true)) {
                throw $error;
            }
        }
    }
}
