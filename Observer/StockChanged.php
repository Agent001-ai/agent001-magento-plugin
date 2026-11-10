<?php

declare(strict_types=1);

namespace Agent001\Assistant\Observer;

use Agent001\Assistant\Model\Sync\ProductQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class StockChanged implements ObserverInterface
{
    /**
     * @param ProductQueue $products
     */
    public function __construct(private readonly ProductQueue $products)
    {
    }

    /**
     * Queue the product of a saved stock item.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $item = $observer->getEvent()->getItem();
        $productId = $item ? (int) $item->getProductId() : 0;
        if ($productId > 0) {
            $this->products->queue(productIds: [$productId]);
        }
    }
}
