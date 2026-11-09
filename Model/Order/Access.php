<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Order;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Lock\LockManagerInterface;

class Access
{
    private const IP_LIMIT = 20;
    private const FAILURE_LIMIT = 5;
    private const FAILURE_TTL = 3600;

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
        return $this->hit(key: 'ip:' . $storeId . ':' . $ip . ':' . $window, limit: self::IP_LIMIT, ttl: 120);
    }

    public function blocked(int $storeId, string $ip, string $number, string $email): bool
    {
        foreach ($this->failureKeys(storeId: $storeId, ip: $ip, number: $number, email: $email) as $key) {
            if ((int) $this->cache->load($this->cacheKey(key: $key)) >= self::FAILURE_LIMIT) {
                return true;
            }
        }
        return false;
    }

    public function fail(int $storeId, string $ip, string $number, string $email): void
    {
        foreach ($this->failureKeys(storeId: $storeId, ip: $ip, number: $number, email: $email) as $key) {
            $this->hit(key: $key, limit: PHP_INT_MAX, ttl: self::FAILURE_TTL);
        }
    }

    /**
     * @return list<string>
     */
    private function failureKeys(int $storeId, string $ip, string $number, string $email): array
    {
        return [
            'fail_email:' . $storeId . ':' . mb_strtolower(string: trim(string: $email)),
            'fail_number:' . $storeId . ':' . $ip . ':' . mb_strtolower(string: trim(string: $number)),
        ];
    }

    private function hit(string $key, int $limit, int $ttl): bool
    {
        $key = $this->cacheKey(key: $key);
        $lock = substr(string: $key, offset: 0, length: 64);
        if (!$this->locks->lock($lock, 2)) {
            return false;
        }
        try {
            $count = (int) $this->cache->load($key);
            if ($count >= $limit) {
                return false;
            }
            $this->cache->save((string) ($count + 1), $key, [], $ttl);
            return true;
        } finally {
            $this->locks->unlock($lock);
        }
    }

    private function cacheKey(string $key): string
    {
        return 'agent001_rate_' . hash(algo: 'sha256', data: $key);
    }
}
