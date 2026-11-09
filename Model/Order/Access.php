<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Order;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Lock\LockManagerInterface;

class Access
{
    public function __construct(private readonly CacheInterface $cache, private readonly LockManagerInterface $locks)
    {
    }

    public function role(string $key, array $setting): string
    {
        if (empty($key) || empty($setting['api_key'])) {
            return '';
        }
        if (hash_equals(known_string: $setting['api_key'], user_string: $key)) {
            return 'backend';
        }
        return !empty($setting['widget_key']) && hash_equals(known_string: $setting['widget_key'], user_string: $key)
            ? 'widget' : '';
    }

    public function allow(int $storeId, string $ip): bool
    {
        $window = intdiv(num1: time(), num2: 60);
        $key = 'agent001_rate_' . hash(algo: 'sha256', data: $storeId . ':' . $ip . ':' . $window);
        $lock = substr(string: $key, offset: 0, length: 64);
        if (!$this->locks->lock(name: $lock, timeout: 2)) {
            return false;
        }
        try {
            $count = (int) $this->cache->load(identifier: $key);
            if ($count >= 20) {
                return false;
            }
            $this->cache->save(data: (string) ($count + 1), identifier: $key, tags: [], lifeTime: 120);
            return true;
        } finally {
            $this->locks->unlock(name: $lock);
        }
    }
}
