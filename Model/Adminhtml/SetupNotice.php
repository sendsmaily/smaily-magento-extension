<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Framework\App\ResourceConnection;

/**
 * The "Smaily Connect is ready to set up" admin notice that the install adds
 * (Setup\Patch\Data\AddSetupNotice). Finishing the initial setup marks it
 * read; uninstalling removes it (Setup\Uninstall).
 *
 * The notice is found by its Read Details link, which no other notice
 * carries. Magento_AdminNotification owns the table; when that module is
 * disabled there is no table and nothing to do.
 */
class SetupNotice
{
    public const TABLE = 'adminnotification_inbox';

    /** Hosted on GitHub for now — update once a hosted docs site exists. */
    public const URL = 'https://github.com/erkkimarkus/magento-connect/blob/v3/docs/USER_GUIDE.md';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Mark the notice read once the initial setup is finished.
     */
    public function markRead(): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        if (!$connection->isTableExists($table)) {
            return;
        }

        $connection->update($table, ['is_read' => 1], ['url = ?' => self::URL, 'is_read = ?' => 0]);
    }
}
