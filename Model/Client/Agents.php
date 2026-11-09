<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Client;

use Agent001\Assistant\Model\Client\DTO\AgentDTO;
use Agent001\Assistant\Model\Client\DTO\AgentListDTO;
use Agent001\Assistant\Model\Client\DTO\AgentStatusDTO;
use UnexpectedValueException;

class Agents
{
    public function __construct(private readonly Client $client)
    {
    }

    public function listing(string $apiKey, string $channel): AgentListDTO
    {
        $response = $this->client->request(
            method: 'GET',
            path: '/api/v1/integrations/me/agents',
            apiKey: $apiKey,
            channel: $channel,
        );
        return AgentListDTO::from(record: $response);
    }

    public function hosts(string $apiKey, array $hosts): void
    {
        $this->client->request(
            method: 'PUT',
            path: '/api/v1/integrations/me/store-hosts',
            apiKey: $apiKey,
            data: ['hosts' => array_values(array: $hosts)],
        );
    }

    public function select(string $apiKey, string $channel, string $id, string $site): AgentDTO
    {
        $this->client->request(
            method: 'PUT',
            path: '/api/v1/integrations/me/catalogs/' . rawurlencode(string: $channel),
            apiKey: $apiKey,
        );
        $response = $this->client->request(
            method: 'PUT',
            path: '/api/v1/integrations/me/agents',
            apiKey: $apiKey,
            data: ['agent_ids' => [$id]],
            channel: $channel,
        );
        $response = AgentListDTO::from(record: $response);
        foreach ($response->agents as $agent) {
            if ($agent->id === $id && !empty($agent->embedToken)
                && in_array(needle: $id, haystack: $response->selectedIds, strict: true)) {
                $this->client->request(
                    method: 'POST',
                    path: '/api/v1/integrations/me/agents/' . rawurlencode(string: $id) . '/domain',
                    apiKey: $apiKey,
                    data: ['domain' => parse_url(url: $site, component: PHP_URL_HOST)],
                    channel: $channel,
                );
                return $agent;
            }
        }
        throw new UnexpectedValueException(message: 'The backend did not confirm the selected agent.');
    }

    public function toggle(string $apiKey, string $channel, string $id, bool $active): void
    {
        $response = $this->client->request(
            method: 'POST',
            path: '/api/v1/integrations/me/agents/' . rawurlencode(string: $id) . '/active',
            apiKey: $apiKey,
            data: ['active' => $active],
            channel: $channel,
        );
        $response = AgentStatusDTO::from(record: $response);
        if ($response->id !== $id || $response->active !== $active) {
            throw new UnexpectedValueException(message: 'The backend did not confirm the agent status.');
        }
    }
}
