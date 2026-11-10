<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Catalog;

use InvalidArgumentException;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Api\Data\StoreInterface;

class Stock
{
    /**
     * @var bool|null
     */
    private ?bool $msi = null;

    /**
     * @param ModuleManager $modules
     * @param ObjectManagerInterface $objects
     * @param StockRegistryInterface $registry
     * @param IndexerRegistry $indexers
     * @param StockRegistryStorage $storage
     */
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ObjectManagerInterface $objects,
        private readonly StockRegistryInterface $registry,
        private readonly IndexerRegistry $indexers,
        private readonly StockRegistryStorage $storage,
    ) {
    }

    /**
     * Clear cached stock data.
     */
    public function reset(): void
    {
        $this->storage->clean();
    }

    /**
     * Check whether the stock indexer has pending changes.
     */
    public function indexing(): bool
    {
        try {
            $indexer = $this->indexers->get($this->msi() ? 'inventory' : 'cataloginventory_stock');
        } catch (InvalidArgumentException) {
            return false;
        }
        if (!$indexer->isScheduled()) {
            return false;
        }
        $view = $indexer->getView();
        $state = $view->getState()->loadByView($view->getId());
        return (int) $view->getChangelog()->getVersion() > (int) $state->getVersionId();
    }

    /**
     * Return the stock identifier of a store view.
     *
     * @param StoreInterface $store
     */
    public function id(StoreInterface $store): int
    {
        if (!$this->msi()) {
            return 0;
        }
        $stock = $this->objects->get(StockResolverInterface::class)
            ->execute('website', (string) $store->getWebsite()->getCode());
        return (int) $stock->getStockId();
    }

    /**
     * Check whether a product is salable.
     *
     * @param Product $product
     * @param int $stockId
     */
    public function salable(Product $product, int $stockId): bool
    {
        if ($this->msi()) {
            return $this->objects->get(IsProductSalableInterface::class)
                ->execute((string) $product->getSku(), $stockId);
        }
        return (int) $this->registry->getStockStatus((int) $product->getId())->getStockStatus() === 1;
    }

    /**
     * Return the salable quantity of a product.
     *
     * @param Product $product
     * @param int $stockId
     * @param int $websiteId
     */
    public function quantity(Product $product, int $stockId, int $websiteId): ?float
    {
        $item = $this->registry->getStockItem((int) $product->getId(), $websiteId);
        if (!$item->getManageStock()) {
            return null;
        }
        if (!$this->msi()) {
            return (float) $item->getQty();
        }
        return (float) $this->objects->get(GetProductSalableQtyInterface::class)
            ->execute((string) $product->getSku(), $stockId);
    }

    /**
     * Check whether Multi-Source Inventory is enabled.
     */
    private function msi(): bool
    {
        return $this->msi ??= $this->modules->isEnabled('Magento_InventorySales');
    }
}
