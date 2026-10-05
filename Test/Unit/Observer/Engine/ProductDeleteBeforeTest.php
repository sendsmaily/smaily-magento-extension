<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Observer\Engine;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Payload\ParentProductResolver;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Observer\Engine\ProductDeleteBefore;

/**
 * PRO-1231 delete routing: a parent/standalone hard-delete fires ONE §3b
 * catalog/remove row keyed on the raw entity id; a configurable child's
 * delete keeps the per-SKU in_stock=false soft path (§3b is product-level
 * and would tombstone the surviving parent/siblings).
 */
class ProductDeleteBeforeTest extends TestCase
{
    private Settings&MockObject $settings;
    private ParentProductResolver&MockObject $parentResolver;
    private CatalogIngest&MockObject $catalogIngest;

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isConnected')->willReturn(true);
        $this->parentResolver = $this->createMock(ParentProductResolver::class);
        $this->catalogIngest = $this->createMock(CatalogIngest::class);
    }

    public function testParentHardDeleteEnqueuesSection3bRemoveNotASoftTombstone(): void
    {
        $this->parentResolver->method('isConfigurableChild')->with(42)->willReturn(false);
        $this->catalogIngest->expects(self::never())->method('enqueueTombstone');
        $this->catalogIngest->expects(self::once())->method('enqueueRemovals')->with([42]);

        $this->createObserver()->execute($this->observerFor($this->product(42)));
    }

    public function testConfigurableChildDeleteKeepsThePerSkuSoftPath(): void
    {
        $product = $this->product(7);
        $this->parentResolver->method('isConfigurableChild')->with(7)->willReturn(true);
        $this->catalogIngest->expects(self::once())->method('enqueueTombstone')->with($product);
        $this->catalogIngest->expects(self::never())->method('enqueueRemovals');

        $this->createObserver()->execute($this->observerFor($product));
    }

    public function testEngineNotConnectedIsANoOp(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(false);
        $this->catalogIngest->expects(self::never())->method('enqueueRemovals');

        $observer = new ProductDeleteBefore(
            $settings,
            $this->parentResolver,
            $this->catalogIngest
        );
        $observer->execute($this->observerFor($this->product(42)));
    }

    public function testNonProductEventDataIsIgnored(): void
    {
        $this->catalogIngest->expects(self::never())->method('enqueueRemovals');

        $this->createObserver()->execute(
            new Observer(['event' => new Event(['product' => new \stdClass()])])
        );
    }

    private function createObserver(): ProductDeleteBefore
    {
        return new ProductDeleteBefore(
            $this->settings,
            $this->parentResolver,
            $this->catalogIngest
        );
    }

    private function observerFor(Product $product): Observer
    {
        return new Observer(['event' => new Event(['product' => $product])]);
    }

    private function product(int $id): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);

        return $product;
    }
}
