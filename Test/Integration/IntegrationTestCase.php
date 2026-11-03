<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;
use Smaily\Connect\Model\StoreLayout;
use Smaily\Connect\Test\Integration\Support\TestClock;
use Smaily\Connect\Test\Integration\Support\TestEnvironment;

/**
 * Base class for integration tests: real MySQL, truncated module tables
 * and a re-frozen clock before every test.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected TestEnvironment $env;

    protected ObjectManagerInterface $objectManager;

    protected AdapterInterface $connection;

    protected TestClock $clock;

    protected function setUp(): void
    {
        $this->env = TestEnvironment::instance();
        $this->env->resetState();
        $this->objectManager = $this->env->getObjectManager();
        $this->connection = $this->env->getConnection();
        $this->clock = $this->env->getClock();
    }

    /**
     * The store's layout (CON-279) over the real tables, on the websites
     * the store manager lists; the events it dispatches go nowhere.
     */
    protected function storeLayoutFor(StoreManagerInterface $storeManager): StoreLayout
    {
        return $this->objectManager->create(StoreLayout::class, [
            'storeManager' => $storeManager,
            'overrides' => $this->objectManager->create(WebsiteOverrides::class, ['storeManager' => $storeManager]),
            'eventManager' => $this->createMock(EventManagerInterface::class),
        ]);
    }

    /**
     * Fetch all rows of a table, ordered by a column, as associative arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchAll(string $table, string $orderBy = 'id'): array
    {
        return $this->connection->fetchAll(
            $this->connection->select()->from($table)->order($orderBy . ' ASC')
        );
    }

    /**
     * Fetch a single row by id, failing the test when it is missing.
     *
     * @return array<string, mixed>
     */
    protected function fetchRow(string $table, int $id, string $idColumn = 'id'): array
    {
        $row = $this->connection->fetchRow(
            $this->connection->select()->from($table)->where($idColumn . ' = ?', $id)
        );
        self::assertIsArray($row, sprintf('Expected row %d in %s', $id, $table));

        return $row;
    }

    /**
     * The frozen "now" formatted the way queue code writes datetimes.
     */
    protected function clockDate(int $offsetSeconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', $this->clock->now() + $offsetSeconds);
    }
}
