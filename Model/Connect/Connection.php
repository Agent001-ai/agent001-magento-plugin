<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Connect;

use Agent001\Assistant\Model\Client\Agents;
use Agent001\Assistant\Model\Client\Client;
use Agent001\Assistant\Model\Client\DTO\ConnectionDTO;
use Agent001\Assistant\Model\Config;
use Agent001\Assistant\Model\Settings;
use Agent001\Assistant\Model\Sync\Queue;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class Connection
{
    public const ALREADY_CONNECTED = 'Disconnect the store before connecting again.';

    /**
     * @param Settings $settings
     * @param Config $config
     * @param NonceStore $nonces
     * @param Client $client
     * @param Queue $queue
     * @param LockManagerInterface $locks
     * @param Agents $agents
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly Config $config,
        private readonly NonceStore $nonces,
        private readonly Client $client,
        private readonly Queue $queue,
        private readonly LockManagerInterface $locks,
        private readonly Agents $agents,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Return the Agent001 URL that starts the connection.
     *
     * @param int $storeId
     * @param string $settingsUrl
     */
    public function start(int $storeId, string $settingsUrl): string
    {
        if (!$this->locks->lock('agent001_connect', 0)) {
            throw new RuntimeException(message: 'Another connection is in progress.');
        }
        try {
            if (!empty($this->settings->apiKey())) {
                throw new RuntimeException(message: self::ALREADY_CONNECTED);
            }
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
            $this->locks->unlock('agent001_connect');
        }
    }

    /**
     * Exchange the connection code for an API key.
     *
     * @param array $state
     * @param string $code
     */
    public function complete(array $state, string $code): void
    {
        if (!$this->locks->lock('agent001_connect', 0)) {
            throw new RuntimeException(message: 'Another connection is in progress.');
        }
        try {
            $installation = $this->settings->get(storeId: Settings::INSTALLATION);
            if (!empty($installation['api_key'])) {
                throw new RuntimeException(message: self::ALREADY_CONNECTED);
            }
            if (($installation['connect_revision'] ?? '') !== $state['revision']
                || $this->config->defaultSite() !== $state['site']) {
                throw new RuntimeException(message: 'Connection state has changed.');
            }
            $response = $this->client->request(
                method: 'POST',
                path: '/connect/exchange',
                data: ['code' => $code],
                attempts: 1,
                timeout: 10,
            );
            $response = ConnectionDTO::from(record: $response);
            $this->settings->save(storeId: Settings::INSTALLATION, data: [
                'api_key' => $response->apiKey, 'account_name' => $response->accountName, 'site' => $state['site'],
            ]);
            $storeId = (int) $state['store_id'];
            if (empty($this->settings->get(storeId: $storeId)['channel'])) {
                $this->open(storeId: $storeId);
            }
        } finally {
            $this->locks->unlock('agent001_connect');
        }
    }

    /**
     * Prepare a store view for a new channel.
     *
     * @param int $storeId
     */
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

    /**
     * Move the connection and store views to their current URLs.
     */
    public function relocate(): void
    {
        $installation = $this->settings->get(storeId: Settings::INSTALLATION);
        $apiKey = (string) ($installation['api_key'] ?? '');
        if (empty($apiKey)) {
            throw new RuntimeException(message: 'The store is not connected to Agent001.');
        }
        $site = $this->config->defaultSite();
        if (($installation['site'] ?? '') !== $site) {
            $this->agents->site(apiKey: $apiKey, site: $site);
            $installation['site'] = $site;
            $this->settings->save(storeId: Settings::INSTALLATION, data: $installation);
        }
        $this->agents->hosts(apiKey: $apiKey, hosts: $this->config->storeHosts());
        foreach ($this->settings->connected() as $storeId => $setting) {
            $current = $this->config->site(storeId: $storeId);
            if (empty($setting['channel']) || ($setting['site'] ?? '') === $current) {
                continue;
            }
            if (!empty($setting['agent_id'])) {
                $this->agents->domain(
                    apiKey: $apiKey,
                    channel: $setting['channel'],
                    id: $setting['agent_id'],
                    site: $current,
                );
            }
            $this->queue->clear(storeId: $storeId);
            $setting['site'] = $current;
            $setting['generation'] = bin2hex(string: random_bytes(length: 16));
            $setting['message'] = '';
            if (!empty($setting['agent_id'])) {
                $setting['status'] = 'queued';
            }
            $this->settings->save(storeId: $storeId, data: $setting, invalidate: true);
            if (!empty($setting['agent_id'])) {
                $this->queue->enqueue(storeId: $storeId, generation: $setting['generation']);
            }
        }
    }

    /**
     * Disconnect a store view from Agent001.
     *
     * @param int $storeId
     */
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

    /**
     * Disconnect the installation and all store views and return whether Agent001 was notified.
     */
    public function disconnectAll(): bool
    {
        $notified = true;
        $installation = $this->settings->get(storeId: Settings::INSTALLATION);
        if (!empty($installation['api_key'])) {
            try {
                $this->tolerate(method: 'DELETE', path: '/api/v1/integrations/me', apiKey: $installation['api_key']);
            } catch (RuntimeException $error) {
                if ($error->getCode() > 0 && $error->getCode() < 500) {
                    throw $error;
                }
                $this->logger->warning('Agent001 could not be notified about the disconnection.', [
                    'exception_class' => $error::class, 'message' => $error->getMessage(),
                ]);
                $notified = false;
            }
        }
        foreach ($this->settings->storeIds() as $storeId) {
            $this->reset(storeId: $storeId);
        }
        $this->settings->save(storeId: Settings::INSTALLATION, data: []);
        return $notified;
    }

    /**
     * Clear store view settings and its queue.
     *
     * @param int $storeId
     */
    private function reset(int $storeId): void
    {
        $this->queue->clear(storeId: $storeId);
        $this->settings->save(storeId: $storeId, data: [
            'widget_key' => bin2hex(string: random_bytes(length: 32)),
            'generation' => bin2hex(string: random_bytes(length: 16)),
        ], invalidate: true);
    }

    /**
     * Send a request that ignores missing or revoked resources.
     *
     * @param string $method
     * @param string $path
     * @param string $apiKey
     */
    private function tolerate(string $method, string $path, string $apiKey): void
    {
        try {
            $this->client->request(method: $method, path: $path, apiKey: $apiKey, attempts: 1, timeout: 10);
        } catch (RuntimeException $error) {
            if (!in_array(needle: $error->getCode(), haystack: [401, 404], strict: true)) {
                throw $error;
            }
        }
    }
}
