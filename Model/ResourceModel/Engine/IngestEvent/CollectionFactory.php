<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ResourceModel\Engine\IngestEvent;

use Magento\Framework\ObjectManagerInterface;

/**
 * Creates ingest event collections.
 */
class CollectionFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    public function create(): Collection
    {
        return $this->objectManager->create(Collection::class);
    }
}
