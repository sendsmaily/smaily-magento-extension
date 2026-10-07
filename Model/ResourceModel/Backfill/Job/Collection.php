<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ResourceModel\Backfill\Job;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\ResourceModel\Backfill\Job as JobResource;

class Collection extends AbstractCollection
{
    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(Job::class, JobResource::class);
    }
}
