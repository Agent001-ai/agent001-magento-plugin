<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model;

use InvalidArgumentException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\App\DeploymentConfig;
use Magento\Store\Model\StoreManagerInterface;

class Config
{
    private const HOST_PATTERN = '/\A(?=.{1,253}\z)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/';

    /**
     * @param DeploymentConfig $deployment
     * @param StoreManagerInterface $stores
     */
    public function __construct(
        private readonly DeploymentConfig $deployment,
        private readonly StoreManagerInterface $stores,
    ) {
    }

    /**
     * Return the Agent001 base URL for the browser or the server.
     *
     * @param bool $internal
     */
    public function baseUrl(bool $internal = false): string
    {
        $public = $this->deployment->get('agent001/base_url', 'https://app.agent001.ai');
        $url = $internal
            ? $this->deployment->get('agent001/internal_base_url', $public)
            : $public;
        $this->validateUrl(url: (string) $url);
        return rtrim(string: (string) $url, characters: '/');
    }

    /**
     * Return the validated base URL of an active store view.
     *
     * @param int $storeId
     */
    public function site(int $storeId): string
    {
        $store = $this->stores->getStore($storeId);
        if (!$store->getId() || !$store->isActive()) {
            throw new InvalidArgumentException(message: 'The store view is inactive.');
        }
        $url = $store->getBaseUrl('link', true);
        $this->validateUrl(url: $url);
        return rtrim(string: $url, characters: '/');
    }

    /**
     * Check whether a store view exists and is active.
     *
     * @param int $storeId
     */
    public function active(int $storeId): bool
    {
        try {
            $store = $this->stores->getStore($storeId);
        } catch (NoSuchEntityException) {
            return false;
        }
        return (bool) $store->getId() && (bool) $store->isActive();
    }

    /**
     * Return the base URL of the default store view.
     */
    public function defaultSite(): string
    {
        $store = $this->stores->getDefaultStoreView();
        if (!$store) {
            throw new InvalidArgumentException(message: 'The default store view is not configured.');
        }
        return $this->site(storeId: (int) $store->getId());
    }

    /**
     * Return the Agent001 channel code of a store view.
     *
     * @param int $storeId
     */
    public function channel(int $storeId): string
    {
        return 'store:' . $this->stores->getStore($storeId)->getCode();
    }

    /**
     * Return hosts of active store views.
     */
    public function storeHosts(): array
    {
        $hosts = [];
        foreach ($this->stores->getStores() as $store) {
            if (!$store->isActive()) {
                continue;
            }
            $url = (string) $store->getBaseUrl('link', true);
            $host = strtolower(string: (string) parse_url(url: $url, component: PHP_URL_HOST));
            if (preg_match(pattern: self::HOST_PATTERN, subject: $host)) {
                $hosts[$host] = true;
            }
        }
        return array_slice(array: array_keys(array: $hosts), offset: 0, length: 50);
    }

    /**
     * Return the origin of a URL.
     *
     * @param string $url
     * @param bool $withPort
     */
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

    /**
     * Return additional origins allowed by deployment configuration.
     */
    public function allowedOrigins(): array
    {
        return (array) $this->deployment->get('agent001/allowed_origins', []);
    }

    /**
     * Reject URLs that are not plain HTTPS or local HTTP addresses.
     *
     * @param string $url
     */
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
