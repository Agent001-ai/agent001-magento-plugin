<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

class Channels
{
    public function __construct(private readonly StoreManagerInterface $stores, private readonly Settings $settings)
    {
    }

    /**
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

    public function defaultId(): int
    {
        return (int) $this->stores->getDefaultStoreView()?->getId();
    }

    public function id(mixed $value): int
    {
        if (!is_scalar(value: $value) || !ctype_digit(text: (string) $value) || !isset($this->stores()[(int) $value])) {
            throw new LocalizedException(phrase: __('Select an active store view.'));
        }
        return (int) $value;
    }
}
