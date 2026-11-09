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
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): Json
    {
        $result = $this->json->create();
        $result->setHeader(name: 'Cache-Control', value: 'no-store, private', replace: true);
        $result->setHeader(name: 'Vary', value: 'Origin', replace: true);
        try {
            $storeId = (int) $this->stores->getStore()->getId();
            $setting = $this->settings->get(storeId: $storeId);
            $site = $this->config->site(storeId: $storeId);
            $origin = (string) $this->request->getHeader(name: 'Origin');
            $allowed = [
                Config::origin(url: $site),
                Config::origin(url: $this->config->baseUrl()),
                ...$this->config->allowedOrigins(),
            ];
            $cors = !empty($origin) && in_array(needle: $origin, haystack: $allowed, strict: true);
            if ($cors) {
                $result->setHeader(name: 'Access-Control-Allow-Origin', value: $origin, replace: true);
                $result->setHeader(name: 'Access-Control-Allow-Methods', value: 'GET, POST, OPTIONS', replace: true);
                $result->setHeader(
                    name: 'Access-Control-Allow-Headers',
                    value: 'Content-Type, X-Agent001-Auth',
                    replace: true,
                );
            }
            if ($this->request->getMethod() === 'OPTIONS') {
                return $result->setHttpResponseCode(httpCode: $cors ? 204 : 403)->setData(data: []);
            }
            $auth = (string) $this->request->getHeader(name: 'X-Agent001-Auth');
            $role = $this->access->role(key: $auth, setting: $setting);
            if (empty($role) || ($role === 'widget' && ($setting['site'] ?? '') !== $site)) {
                return $this->error(result: $result, code: 'agent001_unauthorized', status: 401);
            }
            $ip = (string) $this->request->getServerValue(name: 'REMOTE_ADDR');
            if ($role === 'widget' && !$this->access->allow(storeId: $storeId, ip: $ip)) {
                $result->setHeader(name: 'Retry-After', value: '60', replace: true);
                return $this->error(result: $result, code: 'agent001_rate_limited', status: 429);
            }
            $data = $this->request->getParams();
            if ($this->request->getMethod() === 'POST') {
                $body = (string) $this->request->getContent();
                if (strlen(string: $body) > 4096) {
                    return $this->error(result: $result, code: 'agent001_invalid_request', status: 400);
                }
                $data = json_decode(json: $body, associative: true, flags: JSON_THROW_ON_ERROR);
            }
            $email = is_array(value: $data) ? ($data['email'] ?? null) : null;
            $email = is_string(value: $email) ? trim(string: $email) : '';
            if (empty($email)) {
                return $this->error(result: $result, code: 'agent001_missing_email', status: 400);
            }
            $number = $this->request->getParam(key: 'number');
            if (!filter_var(value: $email, filter: FILTER_VALIDATE_EMAIL)
                || !is_string(value: $number) || strlen(string: $number) > 64) {
                return $this->error(result: $result, code: 'agent001_order_not_found', status: 404);
            }
            $order = $this->lookup->find(storeId: $role === 'widget' ? $storeId : null, number: $number, email: $email);
            return $order ? $result->setData(data: $order)
                : $this->error(result: $result, code: 'agent001_order_not_found', status: 404);
        } catch (JsonException) {
            return $this->error(result: $result, code: 'agent001_invalid_request', status: 400);
        } catch (Throwable $error) {
            $this->logger->error(
                message: 'Agent001 order lookup failed.',
                context: ['exception_class' => $error::class],
            );
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

    private function error(Json $result, string $code, int $status): Json
    {
        return $result->setHttpResponseCode(httpCode: $status)
            ->setData(data: ['code' => $code, 'message' => (string) __($code)]);
    }
}
