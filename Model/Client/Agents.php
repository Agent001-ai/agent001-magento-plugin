<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Client;

use Agent001\Assistant\Model\Client\DTO\AgentDTO;
use Agent001\Assistant\Model\Client\DTO\AgentListDTO;
use Agent001\Assistant\Model\Client\DTO\AgentStatusDTO;
use UnexpectedValueException;

class Agents
{
    /**
     * @param Client $client
     */
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Return agents of the connected account.
     *
     * @param string $apiKey
     * @param string $channel
     */
    public function listing(string $apiKey, string $channel): AgentListDTO
    {
        $response = $this->client->request(
            method: 'GET',
            path: '/api/v1/integrations/me/agents',
            apiKey: $apiKey,
            channel: $channel,
            attempts: 1,
            timeout: 5,
        );
        return AgentListDTO::from(record: $response);
    }

    /**
     * Send store hosts allowed to embed the widget.
     *
     * @param string $apiKey
     * @param array $hosts
     */
    public function hosts(string $apiKey, array $hosts): void
    {
        $this->client->request(
            method: 'PUT',
            path: '/api/v1/integrations/me/store-hosts',
            apiKey: $apiKey,
            data: ['hosts' => array_values(array: $hosts)],
            attempts: 1,
            timeout: 10,
        );
    }

    /**
     * Assign an agent to a store view channel.
     *
     * @param string $apiKey
     * @param string $channel
     * @param string $id
     * @param string $site
     */
    public function select(string $apiKey, string $channel, string $id, string $site): AgentDTO
    {
        $this->client->request(
            method: 'PUT',
            path: '/api/v1/integrations/me/catalogs/' . rawurlencode(string: $channel),
            apiKey: $apiKey,
            attempts: 1,
            timeout: 10,
        );
        $response = $this->client->request(
            method: 'PUT',
            path: '/api/v1/integrations/me/agents',
            apiKey: $apiKey,
            data: ['agent_ids' => [$id]],
            channel: $channel,
            attempts: 1,
            timeout: 10,
        );
        $response = AgentListDTO::from(record: $response);
        foreach ($response->agents as $agent) {
            if ($agent->id === $id && !empty($agent->embedToken)
                && in_array(needle: $id, haystack: $response->selectedIds, strict: true)) {
                $this->domain(apiKey: $apiKey, channel: $channel, id: $id, site: $site);
                return $agent;
            }
        }
        throw new UnexpectedValueException(message: 'The backend did not confirm the selected agent.');
    }

    /**
     * Allow an agent to be embedded on the host of a store view.
     *
     * @param string $apiKey
     * @param string $channel
     * @param string $id
     * @param string $site
     */
    public function domain(string $apiKey, string $channel, string $id, string $site): void
    {
        $this->client->request(
            method: 'POST',
            path: '/api/v1/integrations/me/agents/' . rawurlencode(string: $id) . '/domain',
            apiKey: $apiKey,
            data: ['domain' => parse_url(url: $site, component: PHP_URL_HOST)],
            channel: $channel,
            attempts: 1,
            timeout: 10,
        );
    }

    /**
     * Change the store URL of the connection.
     *
     * @param string $apiKey
     * @param string $site
     */
    public function site(string $apiKey, string $site): void
    {
        $this->client->request(
            method: 'PUT',
            path: '/api/v1/integrations/me/site',
            apiKey: $apiKey,
            data: ['site' => $site],
            attempts: 1,
            timeout: 10,
        );
    }

    /**
     * Enable or disable an agent.
     *
     * @param string $apiKey
     * @param string $channel
     * @param string $id
     * @param bool $active
     */
    public function toggle(string $apiKey, string $channel, string $id, bool $active): void
    {
        $response = $this->client->request(
            method: 'POST',
            path: '/api/v1/integrations/me/agents/' . rawurlencode(string: $id) . '/active',
            apiKey: $apiKey,
            data: ['active' => $active],
            channel: $channel,
            attempts: 1,
            timeout: 10,
        );
        $response = AgentStatusDTO::from(record: $response);
        if ($response->id !== $id || $response->active !== $active) {
            throw new UnexpectedValueException(message: 'The backend did not confirm the agent status.');
        }
    }
}
