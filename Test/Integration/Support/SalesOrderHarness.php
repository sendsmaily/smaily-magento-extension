<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support;

use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Item as OrderItemResource;
use Magento\Sales\Model\ResourceModel\Order\Item\Collection as OrderItemCollection;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as OrderItemCollectionFactory;
use Magento\SalesSequence\Model\Manager as SequenceManager;

/**
 * The orders an import reads, from the real sales_order and sales_order_item
 * tables (SchemaInstaller::createSalesOrder() / createSalesOrderItem()), for
 * an IntegrationTestCase. The test's setUp() requires
 * Support/Stub/OrderItemCollectionFactory.php.
 */
trait SalesOrderHarness
{
    /**
     * The orders, read from sales_order the way the import pages through
     * them; unless $withLines is false, each order's getItems() loads its
     * lines the way Magento's Order::getItems() does — a collection load
     * that unserializes every line's product_options.
     */
    private function orderCollectionFactory(bool $withLines = true): OrderCollectionFactory
    {
        $factory = $this->createMock(OrderCollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($withLines): OrderCollection {
            $ids = [];
            $collection = $this->createMock(OrderCollection::class);
            $collection->method('addFieldToFilter')->willReturnCallback(
                function (string $field, array $condition) use (&$ids, $collection): OrderCollection {
                    $ids = array_map('intval', $condition['in']);

                    return $collection;
                }
            );
            $collection->method('getItems')->willReturnCallback(function () use (&$ids, $withLines): array {
                $rows = $this->connection->fetchAll(
                    $this->connection->select()->from('sales_order')->where('entity_id IN (?)', $ids ?: [0])
                );

                return array_map(
                    fn (array $row): Order => $withLines ? $this->order($row) : $this->plainOrder($row),
                    $rows
                );
            });

            return $collection;
        });

        return $factory;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function order(array $row): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getItems'])
            ->getMock();
        $order->setData($row);
        $order->method('getItems')->willReturnCallback(
            fn (): array => $this->orderItemCollectionFactory()->create()
                ->setOrderFilter((int)$row['entity_id'])
                ->getItems()
        );

        return $order;
    }

    /**
     * An order of its sales_order row alone, not a mock, which would keep
     * every call made to it.
     *
     * @param array<string, mixed> $row
     */
    private function plainOrder(array $row): Order
    {
        $order = (new \ReflectionClass(Order::class))->newInstanceWithoutConstructor();
        $order->setData($row);

        return $order;
    }

    private function orderItemCollectionFactory(): OrderItemCollectionFactory
    {
        $resource = $this->objectManager->create(
            OrderItemResource::class,
            ['sequenceManager' => $this->createMock(SequenceManager::class)]
        );
        // Order lines without Magento's Order\Item constructor, whose
        // dependencies the harness does not have; the collection fills them.
        $entityFactory = $this->createMock(EntityFactoryInterface::class);
        $entityFactory->method('create')->willReturnCallback(
            static function (string $class) use ($resource): object {
                $item = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
                (new \ReflectionProperty(AbstractModel::class, '_resource'))->setValue($item, $resource);

                return $item;
            }
        );

        $factory = $this->createMock(OrderItemCollectionFactory::class);
        $factory->method('create')->willReturnCallback(
            fn (): OrderItemCollection => $this->objectManager->create(
                OrderItemCollection::class,
                ['resource' => $resource, 'entityFactory' => $entityFactory]
            )
        );

        return $factory;
    }
}
