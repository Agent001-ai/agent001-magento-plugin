<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model;

use Magento\Framework\DataObject\IdentityInterface;

class CacheIdentity implements IdentityInterface
{
    public function __construct(private readonly int $storeId)
    {
    }

    public function getIdentities(): array
    {
        return ['AGENT001_' . $this->storeId];
    }
}
