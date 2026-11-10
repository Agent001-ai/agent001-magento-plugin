<?php

declare(strict_types=1);

namespace Agent001\Assistant\Block\Adminhtml;

use Agent001\Assistant\Model\Catalog\Categories;
use Agent001\Assistant\Model\Channels;
use Agent001\Assistant\Model\Client\Agents;
use Agent001\Assistant\Model\Client\DTO\AgentDTO;
use Agent001\Assistant\Model\Client\DTO\AgentListDTO;
use Agent001\Assistant\Model\Config;
use Agent001\Assistant\Model\Onboarding;
use Agent001\Assistant\Model\Settings as SettingsStore;
use Agent001\Assistant\Model\Sync\Queue;
use IntlDateFormatter;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Store\Model\ScopeInterface;
use Throwable;

/**
 * @phpstan-type Channel array{
 *     name: string,
 *     website: string,
 *     locale: string,
 *     setting: array<string, mixed>,
 *     live: bool,
 *     stalled: bool,
 *     failures: int,
 * }
 */
class Settings extends Template
{
    /**
     * @var AgentListDTO|null
     */
    private ?AgentListDTO $listing = null;

    /**
     * @var bool
     */
    private bool $listingLoaded = false;

    /**
     * @var array|null
     */
    private ?array $channelList = null;

    /**
     * @var array|null
     */
    private ?array $installation = null;

    /**
     * @var array|null
     */
    private ?array $progress = null;

    /**
     * @param Context $context
     * @param SettingsStore $settings
     * @param Channels $channels
     * @param Categories $categories
     * @param Agents $agents
     * @param Config $config
     * @param Onboarding $onboarding
     * @param Queue $queue
     * @param JsonHexTag $json
     * @param ScopeConfigInterface $scopeConfig
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly SettingsStore $settings,
        private readonly Channels $channels,
        private readonly Categories $categories,
        private readonly Agents $agents,
        private readonly Config $config,
        private readonly Onboarding $onboarding,
        private readonly Queue $queue,
        private readonly JsonHexTag $json,
        private readonly ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Return installation settings without secrets.
     */
    public function installation(): array
    {
        $this->installation ??= $this->settings->get(storeId: SettingsStore::INSTALLATION);
        return array_diff_key($this->installation, array_flip(array: SettingsStore::SECRETS));
    }

    /**
     * Check whether the installation is connected to Agent001.
     */
    public function connected(): bool
    {
        $this->installation();
        return !empty($this->installation['api_key']);
    }

    /**
     * Return active store views with their settings.
     *
     * @return array<int, Channel>
     */
    public function channels(): array
    {
        if ($this->channelList !== null) {
            return $this->channelList;
        }
        $settings = $this->channels->settings();
        $this->channelList = [];
        foreach ($this->channels->stores() as $id => $store) {
            $setting = array_diff_key($settings[$id], array_flip(array: SettingsStore::SECRETS));
            $this->channelList[$id] = [
                'name' => (string) $store->getName(),
                'website' => (string) $store->getWebsite()->getName(),
                'locale' => (string) $this->scopeConfig->getValue(
                    'general/locale/code',
                    ScopeInterface::SCOPE_STORE,
                    $id,
                ),
                'setting' => $setting,
                'live' => !empty($setting['setup_completed'])
                    && !empty($setting['active'])
                    && !empty($setting['embed_token']),
                'stalled' => $this->queue->stalled(storeId: $id),
                'failures' => $this->queue->failures(storeId: $id),
            ];
        }
        return $this->channelList;
    }

    /**
     * Return store views that have an agent assigned.
     *
     * @return array<int, Channel>
     */
    public function assigned(): array
    {
        return array_filter(
            array: $this->channels(),
            callback: static fn (array $channel): bool => !empty($channel['setting']['agent_id']),
        );
    }

    /**
     * Return names of inactive store views that still have an agent.
     *
     * @return array<int,string>
     */
    public function inactive(): array
    {
        return $this->connected() ? $this->channels->inactive() : [];
    }

    /**
     * Check whether the URL of the store or of a connected store view changed.
     */
    public function relocated(): bool
    {
        if (!$this->connected()) {
            return false;
        }
        try {
            if (($this->installation['site'] ?? '') !== $this->config->defaultSite()) {
                return true;
            }
            foreach ($this->assigned() as $storeId => $channel) {
                if (($channel['setting']['site'] ?? '') !== $this->config->site(storeId: $storeId)) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }
        return false;
    }

    /**
     * Return active categories of a store view.
     *
     * @param int $storeId
     */
    public function categories(int $storeId): array
    {
        return $this->categories->all(storeId: $storeId);
    }

    /**
     * Return agents available on the connected Agent001 account.
     */
    public function agents(): array
    {
        return $this->listing()?->agents ?? [];
    }

    /**
     * Return the name of an Agent001 agent by its identifier.
     *
     * @param string $id
     */
    public function agentName(string $id): string
    {
        foreach ($this->agents() as $agent) {
            if ($agent->id === $id) {
                return $agent->name;
            }
        }
        return $id;
    }

    /**
     * Return the display label of a store view.
     *
     * @param int $storeId
     */
    public function storeLabel(int $storeId): string
    {
        $channel = $this->channels()[$storeId] ?? null;
        if (!$channel) {
            return '';
        }
        return empty($channel['locale']) ? $channel['name'] : $channel['name'] . ' (' . $channel['locale'] . ')';
    }

    /**
     * Return the label of another store view whose channel already uses the agent.
     *
     * @param AgentDTO $agent
     * @param int $storeId
     */
    public function agentOwner(AgentDTO $agent, int $storeId): ?string
    {
        $own = $this->channelCode(storeId: $storeId);
        foreach ($agent->channels as $channel) {
            if ($channel === $own) {
                continue;
            }
            foreach (array_keys(array: $this->channels()) as $id) {
                if ($this->channelCode(storeId: $id) === $channel) {
                    return $this->channels()[$id]['name'];
                }
            }
            return $channel;
        }
        return null;
    }

    /**
     * Check whether the agent list could not be loaded for a connected store.
     */
    public function agentsFailed(): bool
    {
        return $this->connected() && !$this->listing();
    }

    /**
     * Return the agent assigned or suggested for a store view.
     *
     * @param int $storeId
     */
    public function chosenAgentId(int $storeId): string
    {
        $channels = $this->channels();
        if (!empty($channels[$storeId]['setting']['agent_id'])) {
            return (string) $channels[$storeId]['setting']['agent_id'];
        }
        $taken = array_map(
            callback: static fn (array $channel): string => (string) $channel['setting']['agent_id'],
            array: $this->assigned(),
        );
        $channel = $this->channelCode(storeId: $storeId);
        $agents = array_filter(
            array: $this->agents(),
            callback: static fn (AgentDTO $agent): bool => !in_array(
                needle: $agent->id,
                haystack: $taken,
                strict: true,
            ),
        );
        foreach ($agents as $agent) {
            if (in_array(needle: $channel, haystack: $agent->channels, strict: true)) {
                return $agent->id;
            }
        }
        if ($storeId !== $this->channels->defaultId() || !empty($taken)) {
            return '';
        }
        $selected = $this->listing()?->selectedIds ?? [];
        foreach ($agents as $agent) {
            if ($agent->id === ($selected[0] ?? null)) {
                return $agent->id;
            }
        }
        return count(value: $agents) === 1 ? reset(array: $agents)->id : '';
    }

    /**
     * Return the stored setup wizard progress.
     */
    public function progress(): array
    {
        return $this->progress ??= $this->onboarding->get();
    }

    /**
     * Check whether the setup wizard is still in progress.
     */
    public function wizard(): bool
    {
        return $this->progress()['status'] !== 'complete';
    }

    /**
     * Return the current setup wizard step.
     */
    public function step(): int
    {
        return $this->onboarding->step(
            connected: $this->connected(),
            channels: array_map(
                callback: static fn (array $channel): array => $channel['setting'],
                array: $this->channels(),
            ),
        );
    }

    /**
     * Return the wizard step to display, limited to reachable steps.
     */
    public function view(): int
    {
        $step = $this->step();
        $requested = $this->getRequest()->getParam('step');
        if (!is_scalar(value: $requested) || !ctype_digit(text: (string) $requested)) {
            return $step;
        }
        $requested = (int) $requested;
        return $requested >= 2 && $requested < $step ? $requested : $step;
    }

    /**
     * Render the confirmation attribute for a form button.
     *
     * @param string $message
     */
    public function confirmHtml(string $message): string
    {
        return empty($message) ? '' : 'data-confirm="' . $this->_escaper->escapeHtmlAttr($message) . '"';
    }

    /**
     * Return the URL of a setup wizard step.
     *
     * @param int $step
     */
    public function stepUrl(int $step): string
    {
        return $this->getUrl('agent001/settings/index', ['step' => $step]);
    }

    /**
     * Return setup wizard step labels.
     */
    public function steps(): array
    {
        return [1 => __('Connect store'), 2 => __('Choose agents'), 3 => __('Catalog'), 4 => __('Check chat')];
    }

    /**
     * Format the last synchronization date for the admin locale.
     *
     * @param string $date
     */
    public function syncDate(string $date): string
    {
        if (empty($date)) {
            return '';
        }
        return $this->_localeDate->formatDateTime(
            $date,
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::SHORT,
        );
    }

    /**
     * Return the Agent001 panel URL.
     */
    public function backendUrl(): string
    {
        return $this->config->baseUrl();
    }

    /**
     * Return the Agent001 URL that creates an agent for a store view.
     *
     * @param int $storeId
     */
    public function createAgentUrl(int $storeId): string
    {
        return $this->config->baseUrl() . '/connect/store-agent?' . http_build_query(data: [
            'site' => $this->installation()['site'] ?? '',
            'channel' => $this->channelCode(storeId: $storeId),
        ]);
    }

    /**
     * Return the URL that reloads the settings page.
     */
    public function refreshUrl(): string
    {
        return $this->getUrl('agent001/settings/index');
    }

    /**
     * Render the opening tag of a settings form with its form key.
     *
     * @param string $id
     */
    public function formStartHtml(string $id = ''): string
    {
        $attribute = empty($id) ? '' : ' id="' . $this->_escaper->escapeHtmlAttr($id) . '"';
        return '<form method="post"' . $attribute
            . ' action="' . $this->_escaper->escapeUrl($this->saveUrl()) . '">'
            . '<input type="hidden" name="form_key" value="'
            . $this->_escaper->escapeHtmlAttr($this->getFormKey()) . '">';
    }

    /**
     * Return the configuration of the settings page script.
     */
    public function jsConfig(): string
    {
        return $this->json->serialize(['Agent001_Assistant/js/settings' => [
            'progressUrl' => $this->getUrl('agent001/settings/progress'),
            'step' => $this->wizard() ? $this->step() : 0,
        ]]);
    }

    /**
     * Load the agent list once per request.
     */
    private function listing(): ?AgentListDTO
    {
        if ($this->listingLoaded) {
            return $this->listing;
        }
        $this->listingLoaded = true;
        if ($this->connected()) {
            try {
                $this->listing = $this->agents->listing(
                    apiKey: (string) $this->installation['api_key'],
                    channel: $this->channelCode(storeId: $this->channels->defaultId()),
                );
            } catch (Throwable) {
                $this->listing = null;
            }
        }
        return $this->listing;
    }

    /**
     * Return the Agent001 channel code of a store view.
     *
     * @param int $storeId
     */
    private function channelCode(int $storeId): string
    {
        $channel = $this->channels()[$storeId]['setting']['channel'] ?? $this->config->channel(storeId: $storeId);
        return (string) $channel;
    }

    /**
     * Return the URL that saves settings.
     */
    private function saveUrl(): string
    {
        return $this->getUrl('agent001/settings/save');
    }
}
