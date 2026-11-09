<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Catalog;

use Generator;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

class Products
{
    public function __construct(
        private readonly CollectionFactory $factory,
        private readonly Categories $categories,
        private readonly StoreManagerInterface $stores,
        private readonly Stock $stock,
        private readonly Money $money,
    ) {
    }

    private const MULTI_PRICE = ['bundle', 'grouped'];

    public function read(int $storeId, array $categoryIds, ?int $onlyId = null): Generator
    {
        $store = $this->stores->getStore(storeId: $storeId);
        $scope = [
            'store' => $store,
            'stock_id' => $this->stock->id(store: $store),
            'currency' => (string) $store->getCurrentCurrencyCode(),
            'categories' => $this->categories->all(storeId: $storeId),
            'media' => $store->getBaseUrl(type: 'media', secure: true) . 'catalog/product/',
        ];
        $after = 0;
        do {
            $collection = $this->factory->create();
            $collection->setStoreId(storeId: $storeId)->addStoreFilter(store: $storeId);
            $collection->addAttributeToSelect(attribute: '*');
            $collection->addAttributeToFilter(attribute: 'status', condition: 1);
            $collection->addAttributeToFilter(attribute: 'visibility', condition: ['in' => [2, 3, 4]]);
            $collection->addFieldToFilter(attribute: 'entity_id', condition: ['gt' => $after]);
            if ($onlyId !== null) {
                $collection->addFieldToFilter(attribute: 'entity_id', condition: $onlyId);
            }
            if (!empty($categoryIds)) {
                $collection->addCategoriesFilter(categoriesFilter: ['in' => $categoryIds]);
            }
            $collection->setOrder(attribute: 'entity_id', dir: 'ASC')->setPageSize(size: 100);
            $items = $collection->getItems();
            foreach ($items as $product) {
                $after = (int) $product->getId();
                $record = $this->map(product: $product, scope: $scope);
                if ($record) {
                    yield $record;
                }
            }
            $more = count(value: $items) === 100 && $onlyId === null;
            $collection->clear();
        } while ($more);
    }

    /**
     * @param array{store: StoreInterface, stock_id: int, currency: string, categories: array, media: string} $scope
     */
    private function map(Product $product, array $scope): ?array
    {
        if (!$this->stock->salable(product: $product, stockId: $scope['stock_id'])) {
            return null;
        }
        $base = $this->variant(product: $product, scope: $scope);
        $variations = [];
        $type = $product->getTypeInstance();
        if ($type instanceof Configurable) {
            $options = $type->getConfigurableAttributesAsArray(product: $product);
            foreach ($type->getUsedProducts(product: $product) as $child) {
                $salable = $this->stock->salable(product: $child, stockId: $scope['stock_id']);
                if ((int) $child->getStatus() !== 1 || !$salable) {
                    continue;
                }
                $variant = $this->variant(product: $child, scope: $scope);
                $params = [];
                foreach ($options as $option) {
                    $code = $option['attribute_code'];
                    $params[(int) $option['attribute_id']] = $child->getData(key: $code);
                    $variant['attributes'][$option['label']] = (string) $child->getAttributeText(attributeCode: $code);
                }
                $variant['url'] = $base['url'] . '#' . http_build_query(data: $params);
                $variations[] = $variant;
            }
            if (empty($variations)) {
                return null;
            }
            $cheapest = $variations[0];
            foreach ($variations as $variation) {
                if ((float) $variation['price'] < (float) $cheapest['price']) {
                    $cheapest = $variation;
                }
            }
            $base['price'] = $cheapest['price'];
            $base['regular_price'] = $cheapest['regular_price'];
            $base['stock'] = null;
        }
        $categoryIds = $product->getCategoryIds();
        $names = [];
        foreach ($categoryIds as $id) {
            if (isset($scope['categories'][$id])) {
                $names[] = $scope['categories'][$id]['name'];
            }
        }
        $images = [];
        foreach (['image', 'small_image', 'thumbnail'] as $field) {
            $image = (string) $product->getData(key: $field);
            if (!empty($image) && $image !== 'no_selection') {
                $images[] = $scope['media'] . ltrim(string: $image, characters: '/');
            }
        }
        $images = array_values(array: array_unique(array: $images));
        $base['attributes']['Magento product type'] = $product->getTypeId();
        if (in_array(needle: $product->getTypeId(), haystack: self::MULTI_PRICE, strict: true)) {
            $base['attributes']['Price type'] = 'Starting price; selection may change the total';
        }
        return $base + [
            'type' => 'product',
            'description' => strip_tags(string: (string) $product->getDescription()),
            'short_description' => strip_tags(string: (string) $product->getShortDescription()),
            'parent_id' => 0,
            'currency' => $scope['currency'],
            'categories' => $names,
            'category_paths' => $this->categories->paths(ids: $categoryIds, categories: $scope['categories']),
            'images' => $images,
            'image_url' => $images[0] ?? '',
            'product_type' => $type instanceof Configurable ? 'variable' : 'simple',
            'on_sale' => (float) $base['price'] < (float) $base['regular_price'],
            'variations' => $variations,
        ];
    }

    /**
     * @param array{store: StoreInterface, stock_id: int, currency: string, categories: array, media: string} $scope
     */
    private function variant(Product $product, array $scope): array
    {
        $prices = $product->getPriceInfo();
        $finalPrice = $prices->getPrice(priceCode: 'final_price');
        $price = in_array(needle: $product->getTypeId(), haystack: self::MULTI_PRICE, strict: true)
            ? (float) $finalPrice->getMinimalPrice()->getValue()
            : (float) $finalPrice->getAmount()->getValue();
        $regular = (float) $prices->getPrice(priceCode: 'regular_price')->getAmount()->getValue();
        $quantity = null;
        if (in_array(needle: $product->getTypeId(), haystack: ['simple', 'virtual', 'downloadable'], strict: true)) {
            $quantity = $this->stock->quantity(
                product: $product,
                stockId: $scope['stock_id'],
                websiteId: (int) $scope['store']->getWebsiteId(),
            );
        }
        $storeId = (int) $scope['store']->getId();
        $attributes = [];
        foreach ($product->getAttributes() as $attribute) {
            if (!$attribute->getIsVisibleOnFront()) {
                continue;
            }
            $value = $attribute->getFrontend()->getValue(object: $product);
            if (is_scalar(value: $value) && (string) $value !== '') {
                $label = (string) $attribute->getStoreLabel(storeId: $storeId);
                $attributes[$label] = strip_tags(string: (string) $value);
            }
        }
        return [
            'id' => (int) $product->getId(),
            'title' => (string) $product->getName(),
            'sku' => (string) $product->getSku(),
            'price' => $this->money->format(amount: $price, currency: $scope['currency']),
            'regular_price' => $this->money->format(amount: max($regular, $price), currency: $scope['currency']),
            'stock' => $quantity,
            'stock_status' => 'instock',
            'url' => (string) $product->getProductUrl(),
            'attributes' => $attributes,
        ];
    }
}
