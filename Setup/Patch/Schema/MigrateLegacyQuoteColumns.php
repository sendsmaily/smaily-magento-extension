<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Setup\Patch\Schema;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;

/**
 * Removes database artifacts left behind by Smaily for Magento <= 2.8.x.
 *
 * The legacy module added reminder_date/is_sent columns to the core quote
 * table and a smaily_customer_sync table without a declarative schema
 * whitelist, so Magento will not drop them on its own after the module
 * rename. Mailed-state is copied into smaily_abandoned_cart first so that
 * upgraded stores do not re-trigger abandoned cart automations for quotes
 * that were already mailed by the legacy module.
 */
class MigrateLegacyQuoteColumns implements SchemaPatchInterface
{
    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $connection = $this->resourceConnection->getConnection('checkout');
        $connection->startSetup();

        $quoteTable = $this->resourceConnection->getTableName('quote', 'checkout');
        $stateTable = $this->resourceConnection->getTableName('smaily_abandoned_cart', 'checkout');

        $hasIsSent = $connection->tableColumnExists($quoteTable, 'is_sent');
        $hasReminderDate = $connection->tableColumnExists($quoteTable, 'reminder_date');

        if ($hasIsSent && $connection->isTableExists($stateTable)) {
            $reminderDate = $hasReminderDate ? 'reminder_date' : new Expression('NULL');
            $select = $connection->select()
                ->from(
                    $quoteTable,
                    [
                        'quote_id' => 'entity_id',
                        'store_id' => 'store_id',
                        'email' => 'customer_email',
                        'status' => new Expression("'mailed'"),
                        'abandoned_at' => $reminderDate,
                        'mail_sent_at' => $reminderDate,
                    ]
                )
                ->where('is_sent = 1');

            $connection->query(
                $connection->insertFromSelect(
                    $select,
                    $stateTable,
                    ['quote_id', 'store_id', 'email', 'status', 'abandoned_at', 'mail_sent_at'],
                    AdapterInterface::INSERT_IGNORE
                )
            );
        }

        if ($hasIsSent) {
            $connection->dropColumn($quoteTable, 'is_sent');
        }
        if ($hasReminderDate) {
            $connection->dropColumn($quoteTable, 'reminder_date');
        }

        $connection->endSetup();

        $defaultConnection = $this->resourceConnection->getConnection();
        $legacySyncTable = $this->resourceConnection->getTableName('smaily_customer_sync');
        if ($defaultConnection->isTableExists($legacySyncTable)) {
            $defaultConnection->dropTable($legacySyncTable);
        }

        return $this;
    }
}
