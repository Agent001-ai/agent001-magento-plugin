<?php

declare(strict_types=1);

namespace Agent001\Assistant\Controller\Connect;

use Agent001\Assistant\Model\Connect\NonceStore;
use Agent001\Assistant\Model\Connect\Connection;
use Agent001\Assistant\Model\Config;
use Agent001\Assistant\Model\Sync\Queue;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class Callback implements HttpGetActionInterface
{
    /**
     * @param RequestInterface $request
     * @param NonceStore $nonces
     * @param Connection $connection
     * @param RedirectFactory $redirect
     * @param RawFactory $raw
     * @param LockManagerInterface $locks
     * @param LoggerInterface $logger
     * @param Queue $queue
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly NonceStore $nonces,
        private readonly Connection $connection,
        private readonly RedirectFactory $redirect,
        private readonly RawFactory $raw,
        private readonly LockManagerInterface $locks,
        private readonly LoggerInterface $logger,
        private readonly Queue $queue,
    ) {
    }

    /**
     * Complete the Agent001 connection and return to settings.
     */
    public function execute()
    {
        $token = $this->request->getParam('state');
        $state = is_string(value: $token) ? $this->nonces->consume(token: $token) : [];
        $current = $this->request->getScheme() . '://' . $this->request->getHttpHost(false);
        if (empty($state) || Config::origin(url: $state['site']) !== Config::origin(url: $current)) {
            return $this->raw->create()
                ->setHttpResponseCode(400)
                ->setContents('Invalid connection state.');
        }
        $lock = $this->queue->lockName(storeId: (int) $state['store_id']);
        $success = false;
        if ($this->locks->lock($lock, 0)) {
            try {
                $code = $this->request->getParam('code');
                if (is_string(value: $code) && !empty($code) && strlen(string: $code) <= 2048) {
                    $this->connection->complete(state: $state, code: $code);
                    $success = true;
                }
            } catch (Throwable $error) {
                $this->logger->error('Agent001 connection exchange failed.', [
                    'exception_class' => $error::class, 'message' => $error->getMessage(),
                ]);
                $success = false;
            } finally {
                $this->locks->unlock($lock);
            }
        }
        $separator = str_contains(haystack: $state['settings_url'], needle: '?') ? '&' : '?';
        $result = $success ? 'success' : 'exchange_failed';
        return $this->redirect->create()
            ->setHeader('Cache-Control', 'no-store', true)
            ->setHeader('Referrer-Policy', 'no-referrer', true)
            ->setUrl($state['settings_url'] . $separator . 'connect=' . $result);
    }
}
