<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Catalog;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

class Categories
{
    public function __construct(
        private readonly CollectionFactory $factory,
        private readonly StoreManagerInterface $stores,
    ) {
    }

    public function all(int $storeId): array
    {
        $root = (int) $this->stores->getStore($storeId)->getRootCategoryId();
        $collection = $this->factory->create();
        $collection->setStoreId($storeId)->addAttributeToSelect(['name', 'url_key', 'is_active']);
        $collection->addFieldToFilter('path', ['like' => '1/' . $root . '/%']);
        $collection->addAttributeToFilter('is_active', 1);
        $collection->setOrder('path', 'ASC');
        $result = [];
        foreach ($collection as $category) {
            $result[(int) $category->getId()] = [
                'id' => (int) $category->getId(), 'name' => (string) $category->getName(),
                'path' => array_map(callback: 'intval', array: explode(separator: '/', string: $category->getPath())),
                'url' => (string) $category->getUrl(),
            ];
        }
        return $result;
    }

    public function paths(array $ids, array $categories): array
    {
        $paths = [];
        foreach ($ids as $id) {
            if (!isset($categories[$id])) {
                continue;
            }
            $names = [];
            foreach ($categories[$id]['path'] as $parent) {
                if (isset($categories[$parent])) {
                    $names[] = $categories[$parent]['name'];
                }
            }
            $paths[] = implode(separator: ' > ', array: $names);
        }
        return $paths;
    }
}
