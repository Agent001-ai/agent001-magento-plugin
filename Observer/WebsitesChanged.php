<?php

declare(strict_types=1);

namespace Agent001\Assistant\Observer;

use Agent001\Assistant\Model\Sync\ProductQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class WebsitesChanged implements ObserverInterface
{
    /**
     * @param ProductQueue $products
     */
    public function __construct(private readonly ProductQueue $products)
    {
    }

    /**
     * Queue products assigned to or removed from websites.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $ids = $observer->getEvent()->getProducts();
        if (is_array(value: $ids)) {
            $this->products->queue(productIds: array_map(callback: 'intval', array: $ids), allWebsites: true);
        }
    }
}
