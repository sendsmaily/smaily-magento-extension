<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ResourceModel\Engine\IngestEvent;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;

class Collection extends AbstractCollection
{
    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(IngestEvent::class, IngestEventResource::class);
    }
}
