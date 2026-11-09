<?php

declare(strict_types=1);

namespace Agent001\Assistant\Observer;

use Agent001\Assistant\Model\Settings;
use Agent001\Assistant\Model\Sync\Queue;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class ProductChanged implements ObserverInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Queue $queue,
        private readonly Configurable $parents,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getProduct();
        if (!$product || !$product->getId()) {
            return;
        }
        $id = (int) $product->getId();
        $ids = [$id, ...$this->parents->getParentIdsByChild($id)];
        foreach ($this->settings->connected() as $storeId => $setting) {
            if (empty($setting['agent_id'])) {
                continue;
            }
            foreach (array_unique(array: $ids) as $productId) {
                $this->queue->enqueue(
                    storeId: $storeId,
                    generation: $setting['generation'],
                    kind: 'product',
                    productId: (int) $productId,
                );
            }
        }
    }
}
