<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Order;

use Agent001\Assistant\Model\Catalog\Money;
use DateTimeImmutable;
use DateTimeZone;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Config;

class Lookup
{
    /**
     * @param CollectionFactory $orders
     * @param OrderRepositoryInterface $repository
     * @param Config $config
     * @param Money $money
     * @param Tracking $tracking
     */
    public function __construct(
        private readonly CollectionFactory $orders,
        private readonly OrderRepositoryInterface $repository,
        private readonly Config $config,
        private readonly Money $money,
        private readonly Tracking $tracking,
    ) {
    }

    /**
     * Return the public status of an order matching its email.
     *
     * @param int|null $storeId
     * @param string $number
     * @param string $email
     */
    public function find(?int $storeId, string $number, string $email): ?array
    {
        $collection = $this->orders->create();
        if ($storeId !== null) {
            $collection->addFieldToFilter('store_id', $storeId);
        }
        $collection->addFieldToFilter('increment_id', $number)->setPageSize(10);
        $email = mb_strtolower(string: trim(string: $email));
        $orderId = 0;
        foreach ($collection as $candidate) {
            if (mb_strtolower(string: trim(string: (string) $candidate->getCustomerEmail())) === $email) {
                $orderId = (int) $candidate->getId();
                break;
            }
        }
        if (!$orderId) {
            return null;
        }
        $order = $this->repository->get($orderId);
        $currency = (string) $order->getOrderCurrencyCode();
        $tracks = [];
        foreach ($order->getTracksCollection() as $track) {
            $trackingNumber = trim(string: (string) $track->getTrackNumber());
            if (!empty($trackingNumber)) {
                $tracks[$trackingNumber] = $this->tracking->entry(
                    number: $trackingNumber,
                    description: $track->getCarrierCode() . ' ' . $track->getTitle()
                        . ' ' . $order->getShippingDescription(),
                );
            }
        }
        $tracks = array_values(array: $tracks);
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $children = $item->getProductType() === 'bundle' && (float) $item->getRowTotal() <= 0
                ? $item->getChildrenItems() : [];
            $subtotal = 0.0;
            $total = 0.0;
            foreach ($children ?: [$item] as $part) {
                $subtotal += (float) $part->getRowTotal();
                $total += (float) $part->getRowTotal() + (float) $part->getTaxAmount()
                    + (float) $part->getDiscountTaxCompensationAmount() - (float) $part->getDiscountAmount();
            }
            $items[] = ['name' => (string) $item->getName(), 'quantity' => (float) $item->getQtyOrdered(),
                'subtotal' => $this->money->format(amount: $subtotal, currency: $currency),
                'total' => $this->money->format(amount: $total, currency: $currency)];
        }
        $completedAt = null;
        if ($order->getState() === 'complete') {
            foreach ($order->getStatusHistories() as $history) {
                if ($history->getStatus() === $order->getStatus()) {
                    $completedAt = $this->date(value: (string) $history->getCreatedAt());
                    break;
                }
            }
        }
        return [
            'order_number' => (string) $order->getIncrementId(),
            'status' => (string) $order->getStatus(),
            'status_label' => (string) __($this->config->getStatusLabel($order->getStatus())),
            'created_at' => $this->date(value: $order->getCreatedAt()),
            'updated_at' => $this->date(value: $order->getUpdatedAt()),
            'paid_at' => null,
            'completed_at' => $completedAt,
            'currency' => $currency,
            'total' => $this->money->format(amount: (float) $order->getGrandTotal(), currency: $currency),
            'shipping_method' => (string) $order->getShippingDescription(),
            'tracking_number' => $tracks[0]['number'] ?? '',
            'tracking_numbers' => array_column(array: $tracks, column_key: 'number'),
            'tracking' => $tracks,
            'items' => $items,
        ];
    }

    /**
     * Format a database date as ISO 8601.
     *
     * @param string|null $value
     */
    private function date(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        $date = new DateTimeImmutable(datetime: $value, timezone: new DateTimeZone(timezone: 'UTC'));
        return $date->format(format: 'c');
    }
}
