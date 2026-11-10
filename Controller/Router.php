<?php

declare(strict_types=1);

namespace Agent001\Assistant\Controller;

use Agent001\Assistant\Controller\Orders\Lookup;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

class Router implements RouterInterface
{
    /**
     * @param ActionFactory $actions
     */
    public function __construct(private readonly ActionFactory $actions)
    {
    }

    /**
     * Match the order API path.
     *
     * @param RequestInterface $request
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        $path = $request->getPathInfo();
        if (!preg_match(pattern: '~^/agent001/v1/orders/([^/]{1,128})/?$~', subject: $path, matches: $matches)) {
            return null;
        }
        $request->setModuleName('agent001')->setControllerName('orders')->setActionName('lookup');
        $request->setRouteName('agent001');
        $request->setParam('number', rawurldecode(string: $matches[1]));
        return $this->actions->create(Lookup::class);
    }
}
