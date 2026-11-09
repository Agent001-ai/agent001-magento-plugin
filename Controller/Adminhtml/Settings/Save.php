<?php

declare(strict_types=1);

namespace Agent001\Assistant\Controller\Adminhtml\Settings;

use Agent001\Assistant\Model\Catalog\Categories;
use Agent001\Assistant\Model\Channels;
use Agent001\Assistant\Model\Client\Agents;
use Agent001\Assistant\Model\Client\DTO\AgentDTO;
use Agent001\Assistant\Model\Config;
use Agent001\Assistant\Model\Connect\Connection;
use Agent001\Assistant\Model\Onboarding;
use Agent001\Assistant\Model\Settings;
use Agent001\Assistant\Model\Sync\Queue;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Agent001_Assistant::settings';

    private const RUNNING = ['queued', 'reading', 'indexing'];

    public function __construct(
        Context $context,
        private readonly Channels $channels,
        private readonly Settings $settings,
        private readonly Connection $connection,
        private readonly Agents $agents,
        private readonly Categories $categories,
        private readonly Queue $queue,
        private readonly Config $config,
        private readonly Onboarding $onboarding,
        private readonly Validator $formKey,
        private readonly LockManagerInterface $locks,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(context: $context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath(path: 'agent001/settings/index');
        if (!$this->formKey->validate(request: $this->getRequest())) {
            $this->messageManager->addErrorMessage(message: __('Invalid form key. Reload the page.'));
            return $redirect;
        }
        $locked = [];
        try {
            $operation = $this->getRequest()->getParam(key: 'operation');
            $targets = $this->targets(operation: $operation);
            foreach ($targets as $storeId) {
                if (!$this->locks->lock(name: 'agent001_store_' . $storeId, timeout: 0)) {
                    throw new LocalizedException(phrase: __('Synchronization is running. Try again later.'));
                }
                $locked[] = $storeId;
            }
            if ($operation === 'connect') {
                $settingsUrl = $this->getUrl(route: 'agent001/settings/index');
                $url = $this->connection->start(storeId: $this->channels->defaultId(), settingsUrl: $settingsUrl);
                return $redirect->setUrl(url: $url);
            }
            if ($operation === 'disconnect') {
                $this->connection->disconnectAll();
                $this->onboarding->reset();
            } elseif ($operation === 'onboarding_finish') {
                if ($this->progress()['step'] < Onboarding::STEPS) {
                    throw new LocalizedException(
                        phrase: __('Complete the first synchronization before finishing setup.'),
                    );
                }
                $this->onboarding->finish();
            } else {
                $apiKey = $this->settings->apiKey();
                if (empty($apiKey)) {
                    throw new LocalizedException(phrase: __('Connect the store first.'));
                }
                match ($operation) {
                    'agents' => $this->assignAgents(apiKey: $apiKey),
                    'toggle' => $this->toggle(apiKey: $apiKey, storeId: $targets[0]),
                    'catalog' => $this->synchronize(storeIds: $targets),
                    default => throw new LocalizedException(phrase: __('Invalid operation.')),
                };
            }
            $this->progress();
            $this->messageManager->addSuccessMessage(message: __('Settings saved.'));
        } catch (LocalizedException $error) {
            $this->messageManager->addErrorMessage(message: $error->getMessage());
        } catch (Throwable $error) {
            $this->logger->error(
                message: 'Agent001 admin operation failed.',
                context: ['exception_class' => $error::class],
            );
            $this->messageManager->addErrorMessage(
                message: __('The operation failed. Check the connection and store host.'),
            );
        } finally {
            foreach ($locked as $storeId) {
                $this->locks->unlock(name: 'agent001_store_' . $storeId);
            }
        }
        return $redirect;
    }

    /**
     * @return list<int>
     */
    private function targets(mixed $operation): array
    {
        $request = $this->getRequest();
        if ($operation === 'toggle') {
            return [$this->channels->id(value: $request->getParam(key: 'store'))];
        }
        if ($operation === 'catalog') {
            $posted = $request->getParam(key: 'catalog', default: []);
            if (!is_array(value: $posted) || empty($posted)) {
                throw new LocalizedException(phrase: __('Select an active store view.'));
            }
            return array_values(array: array_unique(array: array_map(
                callback: fn (mixed $id): int => $this->channels->id(value: $id),
                array: $posted,
            )));
        }
        if ($operation === 'disconnect') {
            return array_keys(array: $this->channels->stores());
        }
        if ($operation === 'agents') {
            $picked = $request->getParam(key: 'agent', default: []);
            $picked = is_array(value: $picked) ? $picked : [];
            return array_keys(array: array_filter(
                array: $this->channels->settings(),
                callback: static fn (array $setting, int $id): bool => ($picked[$id] ?? '')
                    !== ($setting['agent_id'] ?? ''),
                mode: ARRAY_FILTER_USE_BOTH,
            ));
        }
        return [];
    }

    private function assignAgents(string $apiKey): void
    {
        $picked = $this->getRequest()->getParam(key: 'agent', default: []);
        if (!is_array(value: $picked)) {
            throw new LocalizedException(phrase: __('Select an agent.'));
        }
        $choices = [];
        foreach (array_keys(array: $this->channels->stores()) as $storeId) {
            $id = $picked[$storeId] ?? '';
            if (!is_string(value: $id) || (!empty($id) && !preg_match(pattern: AgentDTO::UUID, subject: $id))) {
                throw new LocalizedException(phrase: __('Select an agent.'));
            }
            $choices[$storeId] = $id;
        }
        $chosen = array_filter(array: $choices);
        if (empty($chosen)) {
            throw new LocalizedException(phrase: __('Assign an agent to at least one store view.'));
        }
        if (count(value: array_unique(array: $chosen)) !== count(value: $chosen)) {
            throw new LocalizedException(phrase: __('Each agent can serve only one store view.'));
        }
        uasort(
            array: $choices,
            callback: static fn (string $a, string $b): int => (int) !empty($a) <=> (int) !empty($b),
        );
        $wizard = $this->onboarding->active();
        $hostsSent = false;
        foreach ($choices as $storeId => $id) {
            $setting = $this->settings->get(storeId: $storeId);
            if ($id === ($setting['agent_id'] ?? '')) {
                continue;
            }
            if (empty($id)) {
                $this->connection->disconnect(storeId: $storeId);
                continue;
            }
            if (empty($setting['channel'])) {
                $this->connection->open(storeId: $storeId);
                $setting = $this->settings->get(storeId: $storeId);
            }
            if (!$hostsSent) {
                $this->agents->hosts(apiKey: $apiKey, hosts: $this->config->storeHosts());
                $hostsSent = true;
            }
            $agent = $this->agents->select(
                apiKey: $apiKey,
                channel: $setting['channel'],
                id: $id,
                site: $setting['site'],
            );
            $setting['agent_id'] = $agent->id;
            $setting['embed_token'] = $agent->embedToken;
            $setting['active'] = $agent->active;
            $setting['setup_completed'] = false;
            $setting['status'] = 'idle';
            $setting['generation'] = bin2hex(string: random_bytes(length: 16));
            $this->queue->clear(storeId: $storeId);
            if (!$wizard) {
                $this->queue->enqueue(storeId: $storeId, generation: $setting['generation']);
                $setting['status'] = 'queued';
                $setting['message'] = '';
            }
            $this->settings->save(storeId: $storeId, data: $setting, invalidate: true);
        }
    }

    private function toggle(string $apiKey, int $storeId): void
    {
        $setting = $this->settings->get(storeId: $storeId);
        if (empty($setting['agent_id'])) {
            throw new LocalizedException(phrase: __('Select an agent.'));
        }
        $active = empty($setting['active']);
        $this->agents->toggle(apiKey: $apiKey, channel: $setting['channel'], id: $setting['agent_id'], active: $active);
        $setting['active'] = $active;
        $this->settings->save(storeId: $storeId, data: $setting, invalidate: true);
    }

    /**
     * @param list<int> $storeIds
     */
    private function synchronize(array $storeIds): void
    {
        $queued = 0;
        foreach ($storeIds as $storeId) {
            $setting = $this->settings->get(storeId: $storeId);
            if (empty($setting['agent_id'])) {
                throw new LocalizedException(phrase: __('Select an agent.'));
            }
            $categories = $this->selectedCategories(storeId: $storeId);
            $changed = $categories !== ($setting['categories'] ?? []);
            if (!$changed && in_array(needle: $setting['status'] ?? '', haystack: self::RUNNING, strict: true)) {
                continue;
            }
            $setting['categories'] = $categories;
            if ($changed) {
                $setting['generation'] = bin2hex(string: random_bytes(length: 16));
                $setting['setup_completed'] = false;
                $this->queue->clear(storeId: $storeId);
            }
            $this->queue->enqueue(storeId: $storeId, generation: $setting['generation']);
            $setting['status'] = 'queued';
            $setting['message'] = '';
            $queued++;
            $this->settings->save(storeId: $storeId, data: $setting, invalidate: true);
        }
        if (!$queued) {
            throw new LocalizedException(phrase: __('Synchronization is running. Try again later.'));
        }
    }

    private function progress(): array
    {
        return $this->onboarding->sync(
            connected: !empty($this->settings->apiKey()),
            channels: $this->channels->settings(),
        );
    }

    private function selectedCategories(int $storeId): array
    {
        $posted = $this->getRequest()->getParam(key: 'categories', default: []);
        $ids = is_array(value: $posted) ? ($posted[$storeId] ?? []) : null;
        if (!is_array(value: $ids) || count(value: $ids) > 10000) {
            throw new LocalizedException(phrase: __('Invalid categories.'));
        }
        $available = $this->categories->all(storeId: $storeId);
        $selected = [];
        foreach ($ids as $id) {
            if (!is_scalar(value: $id) || !ctype_digit(text: (string) $id) || !isset($available[(int) $id])) {
                throw new LocalizedException(phrase: __('Invalid categories.'));
            }
            $selected[] = (int) $id;
        }
        $selected = array_values(array: array_unique(array: $selected));
        if (empty($selected)) {
            $name = $this->channels->stores()[$storeId]->getName();
            throw new LocalizedException(phrase: __('Select at least one category for %1.', $name));
        }
        return count(value: $selected) === count(value: $available) ? [] : $selected;
    }
}
