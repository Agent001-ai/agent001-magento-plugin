<?php

declare(strict_types=1);

namespace Agent001\Assistant\Observer;

use Agent001\Assistant\Model\Sync\ProductQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class ProductChanged implements ObserverInterface
{
    /**
     * @param ProductQueue $products
     */
    public function __construct(private readonly ProductQueue $products)
    {
    }

    /**
     * Queue the saved or deleted product.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getProduct();
        if ($product && $product->getId()) {
            $this->products->queue(productIds: [(int) $product->getId()]);
        }
    }
}
