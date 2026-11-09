<?php

declare(strict_types=1);

namespace Agent001\Assistant\Controller\Connect;

use Agent001\Assistant\Model\Connect\NonceStore;
use Agent001\Assistant\Model\Connect\Connection;
use Agent001\Assistant\Model\Config;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Throwable;

class Callback implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly NonceStore $nonces,
        private readonly Connection $connection,
        private readonly RedirectFactory $redirect,
        private readonly RawFactory $raw,
        private readonly LockManagerInterface $locks,
    ) {
    }

    public function execute()
    {
        $token = $this->request->getParam(key: 'state');
        $state = is_string(value: $token) ? $this->nonces->consume(token: $token) : [];
        $current = $this->request->getScheme() . '://' . $this->request->getHttpHost(trimPort: false);
        if (empty($state) || Config::origin(url: $state['site']) !== Config::origin(url: $current)) {
            return $this->raw->create()
                ->setHttpResponseCode(httpCode: 400)
                ->setContents(contents: 'Invalid connection state.');
        }
        $lock = 'agent001_store_' . (int) $state['store_id'];
        $success = false;
        if ($this->locks->lock(name: $lock, timeout: 0)) {
            try {
                $code = $this->request->getParam(key: 'code');
                if (is_string(value: $code) && !empty($code) && strlen(string: $code) <= 2048) {
                    $this->connection->complete(state: $state, code: $code);
                    $success = true;
                }
            } catch (Throwable) {
                $success = false;
            } finally {
                $this->locks->unlock(name: $lock);
            }
        }
        $separator = str_contains(haystack: $state['settings_url'], needle: '?') ? '&' : '?';
        $result = $success ? 'success' : 'exchange_failed';
        return $this->redirect->create()
            ->setHeader(name: 'Cache-Control', value: 'no-store', replace: true)
            ->setHeader(name: 'Referrer-Policy', value: 'no-referrer', replace: true)
            ->setUrl(url: $state['settings_url'] . $separator . 'connect=' . $result);
    }
}
