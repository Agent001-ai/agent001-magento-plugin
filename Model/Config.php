<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model;

use InvalidArgumentException;
use Magento\Framework\App\DeploymentConfig;
use Magento\Store\Model\StoreManagerInterface;

class Config
{
    private const HOST_PATTERN = '/\A(?=.{1,253}\z)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/';

    public function __construct(
        private readonly DeploymentConfig $deployment,
        private readonly StoreManagerInterface $stores,
    ) {
    }

    public function baseUrl(bool $internal = false): string
    {
        $public = $this->deployment->get(key: 'agent001/base_url', defaultValue: 'https://app.agent001.ai');
        $url = $internal
            ? $this->deployment->get(key: 'agent001/internal_base_url', defaultValue: $public)
            : $public;
        $this->validateUrl(url: (string) $url);
        return rtrim(string: (string) $url, characters: '/');
    }

    public function site(int $storeId): string
    {
        $store = $this->stores->getStore(storeId: $storeId);
        if (!$store->getId() || !$store->isActive()) {
            throw new InvalidArgumentException(message: 'The store view is inactive.');
        }
        $url = $store->getBaseUrl(type: 'link', secure: true);
        $this->validateUrl(url: $url);
        return rtrim(string: $url, characters: '/');
    }

    public function defaultSite(): string
    {
        $store = $this->stores->getDefaultStoreView();
        if (!$store) {
            throw new InvalidArgumentException(message: 'The default store view is not configured.');
        }
        return $this->site(storeId: (int) $store->getId());
    }

    public function channel(int $storeId): string
    {
        return 'store:' . $this->stores->getStore(storeId: $storeId)->getCode();
    }

    public function storeHosts(): array
    {
        $hosts = [];
        foreach ($this->stores->getStores() as $store) {
            if (!$store->isActive()) {
                continue;
            }
            $url = (string) $store->getBaseUrl(type: 'link', secure: true);
            $host = strtolower(string: (string) parse_url(url: $url, component: PHP_URL_HOST));
            if (preg_match(pattern: self::HOST_PATTERN, subject: $host)) {
                $hosts[$host] = true;
            }
        }
        return array_slice(array: array_keys(array: $hosts), offset: 0, length: 50);
    }

    public static function origin(string $url, bool $withPort = true): string
    {
        $parts = parse_url(url: $url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower(string: $parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $suffix = $withPort && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))
            ? ':' . $port : '';
        return $scheme . '://' . strtolower(string: $parts['host']) . $suffix;
    }

    public function allowedOrigins(): array
    {
        return (array) $this->deployment->get(key: 'agent001/allowed_origins', defaultValue: []);
    }

    private function validateUrl(string $url): void
    {
        $parts = parse_url(url: $url);
        $host = strtolower(string: (string) ($parts['host'] ?? ''));
        $local = in_array(needle: $host, haystack: ['localhost', '127.0.0.1', 'host.docker.internal'], strict: true)
            || str_ends_with(haystack: $host, needle: '.localhost');
        if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['scheme'] ?? '') !== 'https' && !(($parts['scheme'] ?? '') === 'http' && $local))) {
            throw new InvalidArgumentException(message: 'Agent001 requires HTTPS URLs (except local development).');
        }
    }
}
