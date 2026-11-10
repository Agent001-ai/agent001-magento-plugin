<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

class Channels
{
    /**
     * @param StoreManagerInterface $stores
     * @param Settings $settings
     */
    public function __construct(private readonly StoreManagerInterface $stores, private readonly Settings $settings)
    {
    }

    /**
     * Return active store views with the default one first.
     *
     * @return array<int, StoreInterface>
     */
    public function stores(): array
    {
        $default = $this->defaultId();
        $stores = [];
        foreach ($this->stores->getStores() as $store) {
            if ($store->isActive()) {
                $stores[(int) $store->getId()] = $store;
            }
        }
        uksort(
            array: $stores,
            callback: static fn (int $a, int $b): int => [$a !== $default, $a] <=> [$b !== $default, $b],
        );
        return $stores;
    }

    /**
     * Return settings of active store views.
     *
     * @return array<int, array<string, mixed>>
     */
    public function settings(): array
    {
        $settings = [];
        foreach (array_keys(array: $this->stores()) as $id) {
            $settings[$id] = $this->settings->get(storeId: $id);
        }
        return $settings;
    }

    /**
     * Return names of inactive or deleted store views that still have an agent.
     *
     * @return array<int,string>
     */
    public function inactive(): array
    {
        $names = [];
        foreach ($this->stores->getStores() as $store) {
            $names[(int) $store->getId()] = (string) $store->getName();
        }
        $active = $this->stores();
        $inactive = [];
        foreach ($this->settings->storeIds() as $id) {
            if (isset($active[$id]) || empty($this->settings->get(storeId: $id)['agent_id'])) {
                continue;
            }
            $inactive[$id] = $names[$id] ?? '#' . $id;
        }
        return $inactive;
    }

    /**
     * Return the identifier of the default store view.
     */
    public function defaultId(): int
    {
        return (int) $this->stores->getDefaultStoreView()?->getId();
    }

    /**
     * Return a validated active store view identifier.
     *
     * @param mixed $value
     */
    public function id(mixed $value): int
    {
        if (!is_scalar(value: $value) || !ctype_digit(text: (string) $value) || !isset($this->stores()[(int) $value])) {
            throw new LocalizedException(__('Select an active store view.'));
        }
        return (int) $value;
    }
}
