<?php

declare(strict_types=1);

namespace Agent001\Assistant\Controller\Orders;

use Agent001\Assistant\Model\Config;
use Agent001\Assistant\Model\Settings;
use Agent001\Assistant\Model\Order\Access;
use Agent001\Assistant\Model\Order\Lookup as OrderLookup;
use JsonException;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\HttpOptionsActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class Lookup implements
    HttpGetActionInterface,
    HttpPostActionInterface,
    HttpOptionsActionInterface,
    CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $json,
        private readonly StoreManagerInterface $stores,
        private readonly Settings $settings,
        private readonly Config $config,
        private readonly Access $access,
        private readonly OrderLookup $lookup,
        private readonly RemoteAddress $remoteAddress,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): Json
    {
        $result = $this->json->create();
        $result->setHeader('Cache-Control', 'no-store, private', true);
        $result->setHeader('Vary', 'Origin', true);
        try {
            $storeId = (int) $this->stores->getStore()->getId();
            $setting = $this->settings->get(storeId: $storeId);
            $site = $this->config->site(storeId: $storeId);
            $origin = (string) $this->request->getHeader('Origin');
            $allowed = [
                Config::origin(url: $site),
                Config::origin(url: $this->config->baseUrl()),
                ...$this->config->allowedOrigins(),
            ];
            $cors = !empty($origin) && in_array(needle: $origin, haystack: $allowed, strict: true);
            if ($cors) {
                $result->setHeader('Access-Control-Allow-Origin', $origin, true);
                $result->setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS', true);
                $result->setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Agent001-Auth', true);
            }
            $method = $this->request->getMethod();
            if ($method === 'OPTIONS') {
                return $result->setHttpResponseCode($cors ? 204 : 403)->setData([]);
            }
            $auth = (string) $this->request->getHeader('X-Agent001-Auth');
            $role = $this->access->role(key: $auth, setting: $setting);
            if (empty($role) || ($role === 'widget' && ($setting['site'] ?? '') !== $site)) {
                return $this->error(result: $result, code: 'agent001_unauthorized', status: 401);
            }
            $widget = $role === 'widget';
            if ($method !== 'POST') {
                return $this->error(result: $result, code: 'agent001_invalid_request', status: 405);
            }
            $ip = (string) $this->remoteAddress->getRemoteAddress();
            if ($widget && !$this->access->allow(storeId: $storeId, ip: $ip)) {
                return $this->rateLimited(result: $result);
            }
            $body = (string) $this->request->getContent();
            if (strlen(string: $body) > 4096) {
                return $this->error(result: $result, code: 'agent001_invalid_request', status: 400);
            }
            $data = json_decode(json: $body, associative: true, flags: JSON_THROW_ON_ERROR);
            $email = is_array(value: $data) ? ($data['email'] ?? null) : null;
            $email = is_string(value: $email) ? trim(string: $email) : '';
            if (empty($email)) {
                return $this->error(result: $result, code: 'agent001_missing_email', status: 400);
            }
            $number = $this->request->getParam('number');
            if (!filter_var(value: $email, filter: FILTER_VALIDATE_EMAIL)
                || !is_string(value: $number) || strlen(string: $number) > 64) {
                return $this->error(result: $result, code: 'agent001_order_not_found', status: 404);
            }
            if ($widget && $this->access->blocked(storeId: $storeId, ip: $ip, number: $number, email: $email)) {
                return $this->rateLimited(result: $result);
            }
            $order = $this->lookup->find(storeId: $widget ? $storeId : null, number: $number, email: $email);
            if ($order) {
                return $result->setData($order);
            }
            if ($widget) {
                $this->access->fail(storeId: $storeId, ip: $ip, number: $number, email: $email);
            }
            return $this->error(result: $result, code: 'agent001_order_not_found', status: 404);
        } catch (JsonException) {
            return $this->error(result: $result, code: 'agent001_invalid_request', status: 400);
        } catch (Throwable $error) {
            $this->logger->error('Agent001 order lookup failed.', ['exception_class' => $error::class]);
            return $this->error(result: $result, code: 'agent001_unavailable', status: 503);
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    private function rateLimited(Json $result): Json
    {
        $result->setHeader('Retry-After', '60', true);
        return $this->error(result: $result, code: 'agent001_rate_limited', status: 429);
    }

    private function error(Json $result, string $code, int $status): Json
    {
        return $result->setHttpResponseCode($status)->setData(['code' => $code, 'message' => (string) __($code)]);
    }
}
