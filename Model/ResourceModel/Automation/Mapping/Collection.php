<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ResourceModel\Automation\Mapping;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Smaily\Connect\Model\Automation\Mapping;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;

class Collection extends AbstractCollection
{
    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(Mapping::class, MappingResource::class);
    }
}
