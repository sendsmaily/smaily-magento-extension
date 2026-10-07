<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\ModuleDataSetupInterface;

/**
 * Thin ModuleDataSetupInterface adapter over the test ResourceConnection.
 *
 * The production implementation (Magento\Setup\Module\DataSetup) ships with
 * the magento2-base package, which module-only composer installs do not
 * have; the data patches under test only use getConnection()/getTable(),
 * which delegate 1:1 to ResourceConnection here.
 */
class DataSetup implements ModuleDataSetupInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getConnection()
    {
        return $this->resourceConnection->getConnection();
    }

    /**
     * @inheritDoc
     */
    public function setTable($tableName, $realTableName)
    {
        throw new \BadMethodCallException('setTable() is not supported by the integration test DataSetup');
    }

    /**
     * @param string|string[] $tableName
     * @return string
     */
    public function getTable($tableName)
    {
        return $this->resourceConnection->getTableName($tableName);
    }

    /**
     * @inheritDoc
     */
    public function getTablePlaceholder($tableName)
    {
        return $tableName;
    }

    /**
     * @inheritDoc
     */
    public function tableExists($table)
    {
        return $this->getConnection()->isTableExists($this->getTable($table));
    }

    /**
     * @inheritDoc
     */
    public function run($sql)
    {
        $this->getConnection()->query($sql);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function startSetup()
    {
        $this->getConnection()->startSetup();

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function endSetup()
    {
        $this->getConnection()->endSetup();

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getTableRow($table, $idField, $rowId, $field = null, $parentField = null, $parentId = 0)
    {
        throw new \BadMethodCallException('getTableRow() is not supported by the integration test DataSetup');
    }

    /**
     * @inheritDoc
     */
    public function deleteTableRow($table, $idField, $rowId, $parentField = null, $parentId = 0)
    {
        throw new \BadMethodCallException('deleteTableRow() is not supported by the integration test DataSetup');
    }

    /**
     * @param string $table
     * @param string $idField
     * @param string|int $rowId
     * @param string|array<string, mixed> $field
     * @param mixed $value
     * @param string $parentField
     * @param string|int $parentId
     * @return never
     */
    public function updateTableRow($table, $idField, $rowId, $field, $value = null, $parentField = null, $parentId = 0)
    {
        throw new \BadMethodCallException('updateTableRow() is not supported by the integration test DataSetup');
    }

    /**
     * @inheritDoc
     */
    public function getEventManager()
    {
        throw new \BadMethodCallException('getEventManager() is not supported by the integration test DataSetup');
    }

    /**
     * @inheritDoc
     */
    public function getFilesystem()
    {
        throw new \BadMethodCallException('getFilesystem() is not supported by the integration test DataSetup');
    }

    /**
     * @param array<string, mixed> $data
     * @return never
     */
    public function createMigrationSetup(array $data = [])
    {
        throw new \BadMethodCallException('createMigrationSetup() is not supported by the integration test DataSetup');
    }

    /**
     * @inheritDoc
     */
    public function getSetupCache()
    {
        throw new \BadMethodCallException('getSetupCache() is not supported by the integration test DataSetup');
    }
}
