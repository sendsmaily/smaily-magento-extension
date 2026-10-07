<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ResourceModel\Engine;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class IngestEvent extends AbstractDb
{
    public const TABLE_NAME = 'smaily_ingest_queue';

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(self::TABLE_NAME, 'id');
    }
}
