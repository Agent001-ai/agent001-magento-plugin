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
    private const EMAIL_LIMIT = 20;

    /**
     * @param CacheInterface $cache
     * @param LockManagerInterface $locks
     */
    public function __construct(private readonly CacheInterface $cache, private readonly LockManagerInterface $locks)
    {
    }

    /**
     * Return the role granted by an access key.
     *
     * @param string $key
     * @param array $setting
     */
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

    /**
     * Check the per minute request limit of a client address.
     *
     * @param int $storeId
     * @param string $ip
     */
    public function allow(int $storeId, string $ip): bool
    {
        $window = intdiv(num1: time(), num2: 60);
        return $this->hit(key: 'ip:' . $storeId . ':' . $ip . ':' . $window, limit: self::IP_LIMIT, ttl: 120);
    }

    /**
     * Check whether failed lookups exceeded the limit.
     *
     * @param int $storeId
     * @param string $ip
     * @param string $number
     * @param string $email
     */
    public function blocked(int $storeId, string $ip, string $number, string $email): bool
    {
        foreach ($this->failureKeys(storeId: $storeId, ip: $ip, number: $number, email: $email) as $key => $limit) {
            if ($this->failures(key: $key)[0] >= $limit) {
                return true;
            }
        }
        return false;
    }

    /**
     * Count a failed order lookup without extending the existing window.
     *
     * @param int $storeId
     * @param string $ip
     * @param string $number
     * @param string $email
     */
    public function fail(int $storeId, string $ip, string $number, string $email): void
    {
        $keys = $this->failureKeys(storeId: $storeId, ip: $ip, number: $number, email: $email);
        foreach (array_keys(array: $keys) as $key) {
            $cacheKey = $this->cacheKey(key: $key);
            $lock = substr(string: $cacheKey, offset: 0, length: 64);
            if (!$this->locks->lock($lock, 2)) {
                continue;
            }
            try {
                [$count, $expires] = $this->failures(key: $key);
                $expires = $expires > time() ? $expires : time() + self::FAILURE_TTL;
                $this->cache->save(($count + 1) . ':' . $expires, $cacheKey, [], max(1, $expires - time()));
            } finally {
                $this->locks->unlock($lock);
            }
        }
    }

    /**
     * Return failure counter keys of a lookup with their limits.
     *
     * @param int $storeId
     * @param string $ip
     * @param string $number
     * @param string $email
     * @return array<string,int>
     */
    private function failureKeys(int $storeId, string $ip, string $number, string $email): array
    {
        $email = mb_strtolower(string: trim(string: $email));
        return [
            'fail_email:' . $storeId . ':' . $ip . ':' . $email => self::FAILURE_LIMIT,
            'fail_email_total:' . $storeId . ':' . $email => self::EMAIL_LIMIT,
            'fail_number:' . $storeId . ':' . $ip . ':' . mb_strtolower(string: trim(string: $number))
                => self::FAILURE_LIMIT,
        ];
    }

    /**
     * Return the failure count and the window end of a counter.
     *
     * @param string $key
     * @return array{int,int}
     */
    private function failures(string $key): array
    {
        $value = (string) $this->cache->load($this->cacheKey(key: $key));
        [$count, $expires] = array_pad(array: explode(separator: ':', string: $value, limit: 2), length: 2, value: '0');
        if ((int) $expires <= time()) {
            return [0, 0];
        }
        return [(int) $count, (int) $expires];
    }

    /**
     * Increment a rate limit counter.
     *
     * @param string $key
     * @param int $limit
     * @param int $ttl
     */
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

    /**
     * Return the cache key of a rate limit counter.
     *
     * @param string $key
     */
    private function cacheKey(string $key): string
    {
        return 'agent001_rate_' . hash(algo: 'sha256', data: $key);
    }
}
