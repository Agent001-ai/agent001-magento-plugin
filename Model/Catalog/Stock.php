<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Catalog;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Api\Data\StoreInterface;

class Stock
{
    private ?bool $msi = null;

    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ObjectManagerInterface $objects,
        private readonly StockRegistryInterface $registry,
    ) {
    }

    public function id(StoreInterface $store): int
    {
        if (!$this->msi()) {
            return 0;
        }
        $stock = $this->objects->get(StockResolverInterface::class)
            ->execute('website', (string) $store->getWebsite()->getCode());
        return (int) $stock->getStockId();
    }

    public function salable(Product $product, int $stockId): bool
    {
        if ($this->msi()) {
            return $this->objects->get(IsProductSalableInterface::class)
                ->execute((string) $product->getSku(), $stockId);
        }
        return (int) $this->registry->getStockStatus(productId: (int) $product->getId())->getStockStatus() === 1;
    }

    public function quantity(Product $product, int $stockId, int $websiteId): ?float
    {
        $item = $this->registry->getStockItem(productId: (int) $product->getId(), scopeId: $websiteId);
        if (!$item->getManageStock()) {
            return null;
        }
        if (!$this->msi()) {
            return (float) $item->getQty();
        }
        return (float) $this->objects->get(GetProductSalableQtyInterface::class)
            ->execute((string) $product->getSku(), $stockId);
    }

    private function msi(): bool
    {
        return $this->msi ??= $this->modules->isEnabled('Magento_InventorySales');
    }
}
