<?php

declare(strict_types=1);

namespace Agent001\Assistant\Observer;

use Agent001\Assistant\Model\Settings;
use Agent001\Assistant\Model\Sync\Queue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CatalogChanged implements ObserverInterface
{
    public function __construct(private readonly Settings $settings, private readonly Queue $queue)
    {
    }

    public function execute(Observer $observer): void
    {
        foreach ($this->settings->connected() as $storeId => $setting) {
            if (!empty($setting['setup_completed'])) {
                $this->queue->enqueue(storeId: $storeId, generation: $setting['generation'], delay: 60);
            }
        }
    }
}
