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
    public function __construct(private readonly ActionFactory $actions)
    {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        $path = $request->getPathInfo();
        if (!preg_match(pattern: '~^/agent001/v1/orders/([^/]{1,128})/?$~', subject: $path, matches: $matches)) {
            return null;
        }
        $request->setModuleName(value: 'agent001')->setControllerName(value: 'orders')->setActionName(value: 'lookup');
        $request->setRouteName(route: 'agent001');
        $request->setParam(key: 'number', value: rawurldecode(string: $matches[1]));
        return $this->actions->create(actionName: Lookup::class);
    }
}
