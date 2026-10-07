<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ResourceModel\Automation;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Mapping extends AbstractDb
{
    public const TABLE_NAME = 'smaily_automation_mapping';

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(self::TABLE_NAME, 'id');
    }
}
