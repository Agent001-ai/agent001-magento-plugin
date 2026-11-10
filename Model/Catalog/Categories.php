<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Catalog;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

class Categories
{
    private const TTL = 600;

    /**
     * @var array<int,array{at:int,categories:array<int,array<string,mixed>>}>
     */
    private array $cache = [];

    /**
     * @param CollectionFactory $factory
     * @param StoreManagerInterface $stores
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly CollectionFactory $factory,
        private readonly StoreManagerInterface $stores,
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * Return active categories of a store view.
     *
     * @param int $storeId
     * @return array<int,array{id:int,name:string,path:list<int>,url:string}>
     */
    public function all(int $storeId): array
    {
        $cached = $this->cache[$storeId] ?? null;
        if ($cached && time() - $cached['at'] < self::TTL) {
            return $cached['categories'];
        }
        $result = $this->load(storeId: $storeId);
        $this->cache[$storeId] = ['at' => time(), 'categories' => $result];
        return $result;
    }

    /**
     * Return active categories of a product together with their ancestors.
     *
     * @param int $storeId
     * @param int $productId
     * @return array<int,array{id:int,name:string,path:list<int>,url:string}>
     */
    public function forProduct(int $storeId, int $productId): array
    {
        $db = $this->resource->getConnection();
        $ids = $db->fetchCol($db->select()
            ->from($this->resource->getTableName('catalog_category_product'), ['category_id'])
            ->where('product_id = ?', $productId));
        if (empty($ids)) {
            return [];
        }
        $ancestors = [];
        foreach ($this->load(storeId: $storeId, ids: array_map(callback: 'intval', array: $ids)) as $category) {
            foreach ($category['path'] as $id) {
                $ancestors[$id] = true;
            }
        }
        return empty($ancestors) ? [] : $this->load(storeId: $storeId, ids: array_keys(array: $ancestors));
    }

    /**
     * Forget cached categories so the next read loads them again.
     */
    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * Return the selected categories together with all their descendants.
     *
     * @param list<int> $ids
     * @param array<int,array<string,mixed>> $categories
     * @return list<int>
     */
    public function subtree(array $ids, array $categories): array
    {
        $selected = array_flip(array: array_map(callback: 'intval', array: $ids));
        $result = [];
        foreach ($categories as $id => $category) {
            foreach ($category['path'] as $ancestor) {
                if (isset($selected[$ancestor])) {
                    $result[] = (int) $id;
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * Return category paths as lists of names from the top level category.
     *
     * @param array $ids
     * @param array<int,array<string,mixed>> $categories
     * @return list<list<string>>
     */
    public function trails(array $ids, array $categories): array
    {
        $trails = [];
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
            if (!empty($names)) {
                $trails[] = $names;
            }
        }
        return $trails;
    }

    /**
     * Load active categories below the root category of a store view.
     *
     * @param int $storeId
     * @param list<int>|null $ids
     * @return array<int,array{id:int,name:string,path:list<int>,url:string}>
     */
    private function load(int $storeId, ?array $ids = null): array
    {
        $root = (int) $this->stores->getStore($storeId)->getRootCategoryId();
        $collection = $this->factory->create();
        $collection->setStoreId($storeId)->addAttributeToSelect(['name', 'url_key', 'is_active']);
        $collection->addFieldToFilter('path', ['like' => '1/' . $root . '/%']);
        if ($ids !== null) {
            $collection->addFieldToFilter('entity_id', ['in' => $ids]);
        }
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
}
