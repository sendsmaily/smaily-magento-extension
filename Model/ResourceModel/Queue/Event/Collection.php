<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ResourceModel\Queue\Event;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;

class Collection extends AbstractCollection
{
    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(Event::class, EventResource::class);
    }
}
