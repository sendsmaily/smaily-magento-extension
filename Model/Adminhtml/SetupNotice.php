<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Framework\App\ResourceConnection;
use Smaily\Connect\Model\UserGuide;

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

    /** The user guide, set in UserGuide::URL. */
    public const URL = UserGuide::URL;

    /**
     * Read Details links that earlier versions wrote into the notice
     * (3.0.0-rc1 to 3.0.0-rc10). The install writes the notice once, so a store
     * that installed one of them keeps that link; the notice is still found.
     * When UserGuide::URL changes, its old value is added here.
     */
    public const PREVIOUS_URLS = [
        'https://github.com/erkkimarkus/magento-connect/blob/v3/docs/USER_GUIDE.md',
        'https://github.com/sendsmaily/smaily-magento-extension/blob/master/docs/USER_GUIDE.md',
    ];

    /** Every Read Details link the notice can carry: the current one first. */
    public const ALL_URLS = [self::URL, ...self::PREVIOUS_URLS];

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

        $connection->update($table, ['is_read' => 1], ['url IN (?)' => self::ALL_URLS, 'is_read = ?' => 0]);
    }
}
