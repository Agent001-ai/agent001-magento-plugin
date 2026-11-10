<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Catalog;

use Generator;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

class Products
{
    private const MULTI_PRICE = ['bundle', 'grouped'];

    private const PAGE = 100;

    /**
     * @var int
     */
    private int $skipped = 0;

    /**
     * @param CollectionFactory $factory
     * @param Categories $categories
     * @param StoreManagerInterface $stores
     * @param Stock $stock
     * @param Money $money
     * @param Text $text
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CollectionFactory $factory,
        private readonly Categories $categories,
        private readonly StoreManagerInterface $stores,
        private readonly Stock $stock,
        private readonly Money $money,
        private readonly Text $text,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Read exported products of a store view.
     *
     * @param int $storeId
     * @param array $categoryIds
     * @param int|null $onlyId
     */
    public function read(int $storeId, array $categoryIds, ?int $onlyId = null): Generator
    {
        $this->skipped = 0;
        $store = $this->stores->getStore($storeId);
        $categories = $onlyId === null
            ? $this->categories->all(storeId: $storeId)
            : $this->categories->forProduct(storeId: $storeId, productId: $onlyId);
        $scope = [
            'store' => $store,
            'stock_id' => $this->stock->id(store: $store),
            'currency' => (string) $store->getCurrentCurrencyCode(),
            'categories' => $categories,
            'media' => $store->getBaseUrl('media', true) . 'catalog/product/',
        ];
        $filter = empty($categoryIds) ? [] : $this->categories->subtree(ids: $categoryIds, categories: $categories);
        if (!empty($categoryIds) && empty($filter)) {
            return;
        }
        $after = 0;
        do {
            $collection = $this->factory->create();
            $collection->setStoreId($storeId)->addStoreFilter($storeId);
            $collection->addAttributeToSelect('*');
            $collection->addAttributeToFilter('status', 1);
            $collection->addAttributeToFilter('visibility', ['in' => [2, 3, 4]]);
            $collection->addFieldToFilter('entity_id', ['gt' => $after]);
            if ($onlyId !== null) {
                $collection->addFieldToFilter('entity_id', $onlyId);
            }
            if (!empty($filter)) {
                $collection->addCategoriesFilter(['in' => $filter]);
            }
            $collection->setOrder('entity_id', 'ASC')->setPageSize(self::PAGE);
            $items = $collection->getItems();
            foreach ($items as $product) {
                $after = (int) $product->getId();
                $record = $this->safeMap(product: $product, scope: $scope);
                if ($record) {
                    yield $record;
                }
            }
            $more = count(value: $items) === self::PAGE && $onlyId === null;
            $collection->clear();
            $this->stock->reset();
        } while ($more);
    }

    /**
     * Return the number of products skipped by the last read because of errors.
     */
    public function skipped(): int
    {
        return $this->skipped;
    }

    /**
     * Map a product and skip it when its data cannot be read.
     *
     * @param Product $product
     * @param array{store:StoreInterface,stock_id:int,currency:string,categories:array,media:string} $scope
     */
    private function safeMap(Product $product, array $scope): ?array
    {
        try {
            return $this->map(product: $product, scope: $scope);
        } catch (Throwable $error) {
            ++$this->skipped;
            $this->logger->warning('Agent001 skipped a product that could not be exported.', [
                'product_id' => (int) $product->getId(), 'store_id' => (int) $scope['store']->getId(),
                'exception_class' => $error::class, 'message' => $error->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Map a salable product to the Agent001 payload.
     *
     * @param Product $product
     * @param array{store:StoreInterface,stock_id:int,currency:string,categories:array,media:string} $scope
     */
    private function map(Product $product, array $scope): ?array
    {
        if (!$this->stock->salable(product: $product, stockId: $scope['stock_id'])) {
            return null;
        }
        $base = $this->variant(product: $product, scope: $scope);
        $base['attributes'] = $this->attributes(product: $product, storeId: (int) $scope['store']->getId());
        $variations = [];
        $type = $product->getTypeInstance();
        if ($type instanceof Configurable) {
            $variations = $this->variations(product: $product, type: $type, base: $base, scope: $scope);
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
            $base['stock_status'] = 'instock';
        }
        $categoryIds = array_map(callback: 'intval', array: $product->getCategoryIds());
        $names = [];
        foreach ($categoryIds as $id) {
            if (isset($scope['categories'][$id])) {
                $names[] = $scope['categories'][$id]['name'];
            }
        }
        $images = [];
        foreach (['image', 'small_image', 'thumbnail'] as $field) {
            $image = (string) $product->getData($field);
            if (!empty($image) && $image !== 'no_selection') {
                $images[] = $scope['media'] . ltrim(string: $image, characters: '/');
            }
        }
        $images = array_values(array: array_unique(array: $images));
        $base['attributes'][] = ['name' => 'Magento product type', 'options' => [(string) $product->getTypeId()]];
        if (in_array(needle: $product->getTypeId(), haystack: self::MULTI_PRICE, strict: true)) {
            $base['attributes'][] = [
                'name' => 'Price type',
                'options' => ['Starting price; selection may change the total'],
            ];
        }
        return $base + [
            'type' => 'product',
            'description' => $this->text->html(product: $product, code: 'description'),
            'short_description' => $this->text->html(product: $product, code: 'short_description'),
            'parent_id' => 0,
            'currency' => $scope['currency'],
            'categories' => $names,
            'category_paths' => $this->categories->trails(ids: $categoryIds, categories: $scope['categories']),
            'images' => $images,
            'image_url' => $images[0] ?? '',
            'product_type' => $type instanceof Configurable ? 'variable' : 'simple',
            'on_sale' => (float) $base['price'] < (float) $base['regular_price'],
            'variations' => $variations,
        ];
    }

    /**
     * Map salable children of a configurable product.
     *
     * @param Product $product
     * @param Configurable $type
     * @param array $base
     * @param array{store:StoreInterface,stock_id:int,currency:string,categories:array,media:string} $scope
     * @return list<array<string,mixed>>
     */
    private function variations(Product $product, Configurable $type, array $base, array $scope): array
    {
        $options = $type->getConfigurableAttributesAsArray($product);
        $variations = [];
        foreach ($type->getUsedProducts($product) as $child) {
            $salable = $this->stock->salable(product: $child, stockId: $scope['stock_id']);
            if ((int) $child->getStatus() !== 1 || !$salable) {
                continue;
            }
            $variant = $this->variant(product: $child, scope: $scope);
            $params = [];
            $values = [];
            foreach ($options as $option) {
                $code = $option['attribute_code'];
                $params[(int) $option['attribute_id']] = $child->getData($code);
                $value = trim(string: (string) $child->getAttributeText($code));
                $label = trim(string: (string) $option['label']);
                if (!empty($value) && !empty($label)) {
                    $variant['attributes'][] = ['name' => $label, 'option' => $value];
                    $values[] = $value;
                }
            }
            if (!empty($values)) {
                $variant['title'] = $base['title'] . ' - ' . implode(separator: ' / ', array: $values);
            }
            $variant['url'] = $base['url'] . '#' . http_build_query(data: $params);
            $variations[] = $variant;
        }
        return $variations;
    }

    /**
     * Map prices, stock and the URL of a product.
     *
     * @param Product $product
     * @param array{store:StoreInterface,stock_id:int,currency:string,categories:array,media:string} $scope
     */
    private function variant(Product $product, array $scope): array
    {
        $prices = $product->getPriceInfo();
        $finalPrice = $prices->getPrice('final_price');
        $price = in_array(needle: $product->getTypeId(), haystack: self::MULTI_PRICE, strict: true)
            ? (float) $finalPrice->getMinimalPrice()->getValue()
            : (float) $finalPrice->getAmount()->getValue();
        $regular = (float) $prices->getPrice('regular_price')->getAmount()->getValue();
        $quantity = null;
        if (in_array(needle: $product->getTypeId(), haystack: ['simple', 'virtual', 'downloadable'], strict: true)) {
            $quantity = $this->stock->quantity(
                product: $product,
                stockId: $scope['stock_id'],
                websiteId: (int) $scope['store']->getWebsiteId(),
            );
        }
        return [
            'id' => (int) $product->getId(),
            'title' => (string) $product->getName(),
            'sku' => (string) $product->getSku(),
            'price' => $this->money->format(amount: $price, currency: $scope['currency']),
            'regular_price' => $this->money->format(amount: max($regular, $price), currency: $scope['currency']),
            'stock' => $quantity === null ? null : (string) (int) floor(num: $quantity),
            'stock_status' => $quantity !== null && $quantity <= 0 ? 'onbackorder' : 'instock',
            'url' => (string) $product->getProductUrl(),
            'attributes' => [],
        ];
    }

    /**
     * Return storefront attributes of a product as named option lists.
     *
     * @param Product $product
     * @param int $storeId
     * @return list<array{name:string,options:list<string>}>
     */
    private function attributes(Product $product, int $storeId): array
    {
        $attributes = [];
        foreach ($product->getAttributes() as $attribute) {
            if (!$attribute->getIsVisibleOnFront()) {
                continue;
            }
            $label = trim(string: (string) $attribute->getStoreLabel($storeId));
            if (empty($label)) {
                continue;
            }
            $values = $attribute->getFrontendInput() === 'multiselect'
                ? $this->optionTexts(product: $product, attribute: $attribute)
                : [$attribute->getFrontend()->getValue($product)];
            $options = [];
            foreach ($values as $value) {
                if (!is_scalar(value: $value) && !$value instanceof Stringable) {
                    continue;
                }
                $text = $this->text->plain(html: (string) $value);
                if ($text !== '') {
                    $options[] = mb_substr(string: $text, start: 0, length: 1000);
                }
            }
            if (!empty($options)) {
                $attributes[] = ['name' => $label, 'options' => array_values(array: array_unique(array: $options))];
            }
        }
        return $attributes;
    }

    /**
     * Return labels of the selected multiselect options.
     *
     * @param Product $product
     * @param AbstractAttribute $attribute
     * @return list<mixed>
     */
    private function optionTexts(Product $product, AbstractAttribute $attribute): array
    {
        $raw = $product->getData($attribute->getAttributeCode());
        $ids = is_array(value: $raw) ? $raw : explode(separator: ',', string: (string) $raw);
        $texts = [];
        foreach ($ids as $id) {
            if (empty($id)) {
                continue;
            }
            $text = $attribute->getSource()->getOptionText($id);
            foreach (is_array(value: $text) ? $text : [$text] as $item) {
                $texts[] = $item;
            }
        }
        return $texts;
    }
}
