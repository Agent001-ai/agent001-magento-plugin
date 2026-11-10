<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Sync;

use Agent001\Assistant\Model\Settings;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class ProductQueue
{
    private const DELAY = 30;

    /**
     * @param Settings $settings
     * @param Queue $queue
     * @param Configurable $parents
     * @param ProductResource $resource
     * @param StoreManagerInterface $stores
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly Queue $queue,
        private readonly Configurable $parents,
        private readonly ProductResource $resource,
        private readonly StoreManagerInterface $stores,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Queue changed products without interrupting the operation that changed them.
     *
     * @param list<int> $productIds
     * @param bool $allWebsites
     */
    public function queue(array $productIds, bool $allWebsites = false): void
    {
        try {
            $this->enqueue(productIds: $productIds, allWebsites: $allWebsites);
        } catch (Throwable $error) {
            $this->logger->error('Agent001 could not queue changed products.', [
                'exception_class' => $error::class, 'message' => $error->getMessage(),
            ]);
        }
    }

    /**
     * Queue changed products for store views that export them.
     *
     * @param list<int> $productIds
     * @param bool $allWebsites
     */
    private function enqueue(array $productIds, bool $allWebsites): void
    {
        $settings = array_filter(
            array: $this->settings->connected(),
            callback: static fn (array $setting): bool => !empty($setting['agent_id']),
        );
        if (empty($settings) || empty($productIds)) {
            return;
        }
        $ids = $this->exported(productIds: $productIds);
        $websites = $allWebsites ? [] : $this->resource->getWebsiteIdsByProductIds($ids);
        foreach ($settings as $storeId => $setting) {
            $websiteId = (int) $this->stores->getStore($storeId)->getWebsiteId();
            foreach ($ids as $productId) {
                $assigned = array_map(callback: 'intval', array: $websites[$productId] ?? []);
                if (!$allWebsites && !in_array(needle: $websiteId, haystack: $assigned, strict: true)) {
                    continue;
                }
                $this->queue->enqueue(
                    storeId: $storeId,
                    generation: $setting['generation'],
                    kind: 'product',
                    productId: $productId,
                    delay: self::DELAY,
                );
            }
        }
    }

    /**
     * Replace variants that are not visible on their own with their configurable parents.
     *
     * @param list<int> $productIds
     * @return list<int>
     */
    private function exported(array $productIds): array
    {
        $ids = [];
        foreach (array_unique(array: array_map(callback: 'intval', array: $productIds)) as $id) {
            if ($id <= 0) {
                continue;
            }
            $parents = array_map(callback: 'intval', array: $this->parents->getParentIdsByChild($id));
            $visibility = (int) $this->resource->getAttributeRawValue($id, 'visibility', 0);
            if (empty($parents) || $visibility !== Visibility::VISIBILITY_NOT_VISIBLE) {
                $ids[] = $id;
            }
            foreach ($parents as $parent) {
                $ids[] = $parent;
            }
        }
        return array_values(array: array_unique(array: $ids));
    }
}
