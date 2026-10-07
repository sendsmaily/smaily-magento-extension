<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ResourceModel\Log;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Identifier-only resource for the unified log grid: the grid rows are a
 * UNION of the two queue tables keyed by the synthetic "log_id" column
 * ("smaily-<id>" / "intelligence-<id>"). Never used for CRUD — it exists so
 * SearchResult and the mass-action Filter resolve the composite id field.
 */
class UnifiedRow extends AbstractDb
{
    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init('smaily_event_queue', 'log_id');
    }
}
