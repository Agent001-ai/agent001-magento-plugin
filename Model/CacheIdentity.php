<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model;

use Magento\Framework\DataObject\IdentityInterface;

class CacheIdentity implements IdentityInterface
{
    /**
     * @param int $storeId
     */
    public function __construct(private readonly int $storeId)
    {
    }

    /**
     * Return cache tags of a store view.
     */
    public function getIdentities(): array
    {
        return ['AGENT001_' . $this->storeId];
    }
}
