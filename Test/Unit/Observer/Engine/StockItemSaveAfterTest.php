<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Observer\Engine;

use Magento\CatalogInventory\Model\Stock\Item as StockItem;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Observer\Engine\StockItemSaveAfter;

/**
 * PRO-1951: a stock change that bypasses product save must still reach
 * catalog ingest.
 */
class StockItemSaveAfterTest extends TestCase
{
    private CatalogIngest&MockObject $catalogIngest;

    protected function setUp(): void
    {
        $this->catalogIngest = $this->createMock(CatalogIngest::class);
    }

    public function testAStockItemSaveEnqueuesItsProduct(): void
    {
        $this->catalogIngest->expects(self::once())->method('markProductChanged')->with(42);

        $this->observer()->execute($this->eventFor($this->stockItem(42)));
    }

    public function testAnEventWithoutAStockItemIsANoOp(): void
    {
        $this->catalogIngest->expects(self::never())->method('markProductChanged');

        $this->observer()->execute($this->eventFor(null));
    }

    private function observer(): StockItemSaveAfter
    {
        return new StockItemSaveAfter($this->catalogIngest);
    }

    private function stockItem(int $productId): StockItem&MockObject
    {
        $item = $this->createMock(StockItem::class);
        $item->method('getProductId')->willReturn($productId);

        return $item;
    }

    private function eventFor(?StockItem $item): Observer
    {
        $event = new Event(['item' => $item]);

        return new Observer(['event' => $event]);
    }
}
