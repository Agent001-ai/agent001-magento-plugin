<?php

declare(strict_types=1);

namespace Agent001\Assistant\Controller\Adminhtml\Settings;

use Agent001\Assistant\Model\Catalog\Categories;
use Agent001\Assistant\Model\Channels;
use Agent001\Assistant\Model\Client\Agents;
use Agent001\Assistant\Model\Client\UnauthorizedException;
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
use RuntimeException;
use Throwable;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Agent001_Assistant::settings';

    private const RUNNING = ['queued', 'reading', 'indexing'];

    /**
     * @param Context $context
     * @param Channels $channels
     * @param Settings $settings
     * @param Connection $connection
     * @param Agents $agents
     * @param Categories $categories
     * @param Queue $queue
     * @param Config $config
     * @param Onboarding $onboarding
     * @param Validator $formKey
     * @param LockManagerInterface $locks
     * @param LoggerInterface $logger
     */
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
        parent::__construct($context);
    }

    /**
     * Run the requested settings operation.
     */
    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('agent001/settings/index');
        if (!$this->formKey->validate($this->getRequest())) {
            $this->messageManager->addErrorMessage(__('Invalid form key. Reload the page.'));
            return $redirect;
        }
        $lock = '';
        try {
            $operation = $this->getRequest()->getParam('operation');
            if ($operation === 'connect') {
                if (!empty($this->settings->apiKey())) {
                    throw new LocalizedException(__('Disconnect the store before connecting again.'));
                }
                $settingsUrl = $this->getUrl('agent001/settings/index');
                $url = $this->connection->start(storeId: $this->channels->defaultId(), settingsUrl: $settingsUrl);
                return $redirect->setUrl($url);
            }
            if ($operation === 'disconnect') {
                $notified = $this->connection->disconnectAll();
                $this->onboarding->reset();
                $this->progress();
                if (!$notified) {
                    $this->messageManager->addWarningMessage(
                        __('Removed locally; Agent001 could not be notified.'),
                    );
                    return $redirect;
                }
            } elseif ($operation === 'onboarding_finish') {
                if ($this->progress()['step'] < Onboarding::STEPS) {
                    throw new LocalizedException(
                        __('Complete the first synchronization before finishing setup.'),
                    );
                }
                $this->onboarding->finish();
            } else {
                $apiKey = $this->settings->apiKey();
                if (empty($apiKey)) {
                    throw new LocalizedException(__('Connect the store first.'));
                }
                if ($operation === 'toggle') {
                    $storeId = $this->channels->id(value: $this->getRequest()->getParam('store'));
                    if (!$this->locks->lock($this->queue->lockName(storeId: $storeId), 0)) {
                        throw new LocalizedException(__('Synchronization is running. Try again later.'));
                    }
                    $lock = $this->queue->lockName(storeId: $storeId);
                    $this->toggle(apiKey: $apiKey, storeId: $storeId);
                } else {
                    match ($operation) {
                        'agents' => $this->assignAgents(apiKey: $apiKey),
                        'catalog' => $this->synchronize(storeIds: $this->catalogTargets()),
                        'relocate' => $this->connection->relocate(),
                        'retry' => $this->queue->retryFailed(
                            storeId: $this->channels->id(value: $this->getRequest()->getParam('store')),
                        ),
                        'remove_inactive' => $this->connection->disconnect(storeId: $this->inactiveTarget()),
                        default => throw new LocalizedException(__('Invalid operation.')),
                    };
                }
            }
            $this->progress();
            $this->messageManager->addSuccessMessage(__('Settings saved.'));
        } catch (LocalizedException $error) {
            $this->messageManager->addErrorMessage($error->getMessage());
        } catch (UnauthorizedException) {
            $this->messageManager->addErrorMessage(
                __('The Agent001 key was revoked. Disconnect and connect the store again.'),
            );
        } catch (Throwable $error) {
            $this->logger->error('Agent001 admin operation failed.', [
                'exception_class' => $error::class, 'message' => $error->getMessage(),
            ]);
            $this->messageManager->addErrorMessage(
                $error instanceof RuntimeException && $error->getCode() === 409
                    ? __('Another Agent001 connection already uses this store URL.')
                    : __('The operation failed. Check the connection and store host.'),
            );
        } finally {
            if (!empty($lock)) {
                $this->locks->unlock($lock);
            }
        }
        return $redirect;
    }

    /**
     * Return store views posted for catalog synchronization.
     *
     * @return list<int>
     */
    private function catalogTargets(): array
    {
        $posted = $this->getRequest()->getParam('catalog', []);
        if (!is_array(value: $posted) || empty($posted)) {
            throw new LocalizedException(__('Select an active store view.'));
        }
        return array_values(array: array_unique(array: array_map(
            callback: fn (mixed $id): int => $this->channels->id(value: $id),
            array: $posted,
        )));
    }

    /**
     * Return a posted inactive store view that still has an agent.
     */
    private function inactiveTarget(): int
    {
        $value = $this->getRequest()->getParam('store');
        $storeId = is_scalar(value: $value) && ctype_digit(text: (string) $value) ? (int) $value : 0;
        if (!in_array(needle: $storeId, haystack: array_keys(array: $this->channels->inactive()), strict: true)) {
            throw new LocalizedException(__('Select an inactive store view.'));
        }
        return $storeId;
    }

    /**
     * Assign the selected agents to store views.
     *
     * @param string $apiKey
     */
    private function assignAgents(string $apiKey): void
    {
        $picked = $this->getRequest()->getParam('agent', []);
        if (!is_array(value: $picked)) {
            throw new LocalizedException(__('Select an agent.'));
        }
        $choices = [];
        foreach (array_keys(array: $this->channels->stores()) as $storeId) {
            $id = $picked[$storeId] ?? '';
            if (!is_string(value: $id) || (!empty($id) && !preg_match(pattern: AgentDTO::UUID, subject: $id))) {
                throw new LocalizedException(__('Select an agent.'));
            }
            $choices[$storeId] = $id;
        }
        $chosen = array_filter(array: $choices);
        if (empty($chosen)) {
            throw new LocalizedException(__('Assign an agent to at least one store view.'));
        }
        if (count(value: array_unique(array: $chosen)) !== count(value: $chosen)) {
            throw new LocalizedException(__('Each agent can serve only one store view.'));
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

    /**
     * Enable or disable the agent of a store view.
     *
     * @param string $apiKey
     * @param int $storeId
     */
    private function toggle(string $apiKey, int $storeId): void
    {
        $setting = $this->settings->get(storeId: $storeId);
        if (empty($setting['agent_id'])) {
            throw new LocalizedException(__('Select an agent.'));
        }
        $active = empty($setting['active']);
        $this->agents->toggle(apiKey: $apiKey, channel: $setting['channel'], id: $setting['agent_id'], active: $active);
        $setting['active'] = $active;
        $this->settings->save(storeId: $storeId, data: $setting, invalidate: true);
    }

    /**
     * Queue a full synchronization for store views.
     *
     * @param list<int> $storeIds
     */
    private function synchronize(array $storeIds): void
    {
        $queued = 0;
        foreach ($storeIds as $storeId) {
            $setting = $this->settings->get(storeId: $storeId);
            if (empty($setting['agent_id'])) {
                throw new LocalizedException(__('Select an agent.'));
            }
            $categories = $this->selectedCategories(storeId: $storeId);
            $changed = $categories !== ($setting['categories'] ?? []);
            if (!$changed && in_array(needle: $setting['status'] ?? '', haystack: self::RUNNING, strict: true)) {
                continue;
            }
            $setting['categories'] = $categories;
            if ($changed) {
                $setting['generation'] = bin2hex(string: random_bytes(length: 16));
                $this->queue->clear(storeId: $storeId);
            }
            $this->queue->enqueue(storeId: $storeId, generation: $setting['generation']);
            $setting['status'] = 'queued';
            $setting['message'] = '';
            $queued++;
            $this->settings->save(storeId: $storeId, data: $setting, invalidate: true);
        }
        if (!$queued) {
            throw new LocalizedException(__('Synchronization is running. Try again later.'));
        }
    }

    /**
     * Update and return setup wizard progress.
     */
    private function progress(): array
    {
        return $this->onboarding->sync(
            connected: !empty($this->settings->apiKey()),
            channels: $this->channels->settings(),
        );
    }

    /**
     * Return valid category identifiers posted for a store view.
     *
     * @param int $storeId
     */
    private function selectedCategories(int $storeId): array
    {
        $posted = $this->getRequest()->getParam('categories', []);
        $ids = is_array(value: $posted) ? ($posted[$storeId] ?? []) : null;
        if (!is_array(value: $ids) || count(value: $ids) > 10000) {
            throw new LocalizedException(__('Invalid categories.'));
        }
        $available = $this->categories->all(storeId: $storeId);
        $selected = [];
        foreach ($ids as $id) {
            if (!is_scalar(value: $id) || !ctype_digit(text: (string) $id) || !isset($available[(int) $id])) {
                throw new LocalizedException(__('Invalid categories.'));
            }
            $selected[] = (int) $id;
        }
        $selected = array_values(array: array_unique(array: $selected));
        if (empty($selected)) {
            $name = $this->channels->stores()[$storeId]->getName();
            throw new LocalizedException(__('Select at least one category for %1.', $name));
        }
        return count(value: $selected) === count(value: $available) ? [] : $selected;
    }
}
