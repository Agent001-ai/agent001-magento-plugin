<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Catalog;

use Generator;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;

class StoreContext
{
    public function __construct(
        private readonly Categories $categories,
        private readonly CollectionFactory $attributes,
    ) {
    }

    public function read(int $storeId): Generator
    {
        $categories = $this->categories->all(storeId: $storeId);
        foreach ($categories as $category) {
            $path = $this->categories->paths(ids: [$category['id']], categories: $categories)[0] ?? '';
            yield [
                'type' => 'category',
                'id' => 'category:' . $category['id'],
                'title' => mb_substr(string: $category['name'], start: 0, length: 500),
                'content' => mb_substr(string: $path, start: 0, length: 1000),
                'url' => $category['url'],
            ];
        }
        $attributes = $this->attributes->create();
        $attributes->addFieldToFilter(field: 'frontend_input', condition: ['in' => ['select', 'multiselect']]);
        $attributes->addFieldToFilter(field: 'is_user_defined', condition: 1);
        foreach ($attributes as $attribute) {
            $attribute->setStoreId($storeId);
            $code = (string) $attribute->getAttributeCode();
            $label = mb_substr(string: (string) $attribute->getStoreLabel(storeId: $storeId), start: 0, length: 500);
            yield [
                'type' => 'attribute',
                'id' => 'option:' . $code,
                'title' => $label,
                'content' => $label,
                'url' => '',
            ];
            foreach ($attribute->getSource()->getAllOptions(withEmpty: false) as $option) {
                if (!is_scalar(value: $option['value'] ?? null) || !is_scalar(value: $option['label'] ?? null)) {
                    continue;
                }
                $value = mb_substr(string: (string) $option['label'], start: 0, length: 500);
                yield [
                    'type' => 'attribute',
                    'id' => 'option:' . $code . ':' . $option['value'],
                    'title' => $value,
                    'content' => mb_substr(string: $label . ': ' . $value, start: 0, length: 1000),
                    'url' => '',
                ];
            }
        }
    }
}
