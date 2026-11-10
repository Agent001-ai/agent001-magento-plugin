<?php

declare(strict_types=1);

namespace Agent001\Assistant\Observer;

use Agent001\Assistant\Model\Sync\ProductQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class OrderPlaced implements ObserverInterface
{
    /**
     * @param ProductQueue $products
     */
    public function __construct(private readonly ProductQueue $products)
    {
    }

    /**
     * Queue ordered products so their stock is updated.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order) {
            return;
        }
        $ids = [];
        foreach ($order->getAllItems() as $item) {
            $ids[] = (int) $item->getProductId();
        }
        $this->products->queue(productIds: $ids);
    }
}
