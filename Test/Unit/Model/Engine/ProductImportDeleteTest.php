<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\CatalogImportExport\Model\Import\Product as ImportAdapter;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\ImportExport\Model\ResourceModel\Import\Data as DataSource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Payload\ParentProductResolver;
use Smaily\Connect\Model\Engine\ProductImportDelete;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Observer\Engine\ProductImportBunchDelete;
use Smaily\Connect\Plugin\Engine\ProductImportBunch;

/**
 * PRO-3768: products a product import deletes (Delete behaviour) get the
 * removal Magento's own product delete gives them — §3b for a parent or
 * standalone product, the per-SKU tombstone for a configurable child, built
 * while the bunch is read (before the DELETE) and queued when it is deleted.
 * A Replace import, another entity's import and a disconnected engine queue
 * nothing.
 */
class ProductImportDeleteTest extends TestCase
{
    private Settings&MockObject $settings;
    private ProductResource&MockObject $productResource;
    private ParentProductResolver&MockObject $parentResolver;
    private CatalogIngest&MockObject $catalogIngest;
    private IngestQueue&MockObject $queue;

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isConnected')->willReturn(true);
        $this->productResource = $this->createMock(ProductResource::class);
        $this->parentResolver = $this->createMock(ParentProductResolver::class);
        $this->catalogIngest = $this->createMock(CatalogIngest::class);
        $this->queue = $this->createMock(IngestQueue::class);
    }

    public function testADeletedChildGetsItsTombstoneAndTheOthersSection3b(): void
    {
        $this->productResource->method('getProductsIdsBySkus')
            ->with(['TENT', 'TENT-RED', 'LAMP'])
            ->willReturn(['TENT' => '10', 'TENT-RED' => '11', 'LAMP' => '20']);
        $this->parentResolver->method('isConfigurableChild')
            ->willReturnCallback(static fn (int $id): bool => $id === 11);
        $tombstone = ['sku' => 'TENT-RED', 'in_stock' => false];
        $this->catalogIngest->expects(self::once())->method('buildTombstones')->with([11])
            ->willReturn([11 => $tombstone]);
        $this->catalogIngest->expects(self::once())->method('enqueueBuilt')->with([11 => $tombstone]);
        $this->queue->expects(self::once())->method('enqueueMany')->with(Client::DOMAIN_CATALOG_REMOVE, [
            ['payload' => ['product_id' => '10'], 'entity_id' => '10', 'store_id' => null],
            ['payload' => ['product_id' => '20'], 'entity_id' => '20', 'store_id' => null],
        ]);

        $deletes = $this->deletes();
        $plugin = new ProductImportBunch($deletes);
        $bunch = [['sku' => 'TENT'], ['sku' => 'TENT-RED'], ['sku' => 'LAMP'], ['sku' => '']];
        self::assertSame($bunch, $plugin->afterGetNextUniqueBunch($this->dataSource(), $bunch, [5]));

        (new ProductImportBunchDelete($deletes))->execute($this->bunchDeleted($this->dataSource(), [10, '11', 20]));
    }

    /**
     * Built for one bunch, a tombstone is not queued for a later bunch.
     */
    public function testTheTombstonesOfOneBunchAreNotKeptForTheNext(): void
    {
        $this->productResource->method('getProductsIdsBySkus')->willReturn(['TENT-RED' => '11']);
        $this->parentResolver->method('isConfigurableChild')->willReturn(true);
        $this->catalogIngest->method('buildTombstones')->willReturn([11 => ['sku' => 'TENT-RED']]);
        $queued = [];
        $this->catalogIngest->method('enqueueBuilt')->willReturnCallback(
            static function (array $rows) use (&$queued): void {
                $queued[] = $rows;
            }
        );

        $deletes = $this->deletes();
        $deletes->prepare([['sku' => 'TENT-RED']]);
        $deletes->enqueue([11]);
        $deletes->enqueue([11]);

        self::assertSame([[11 => ['sku' => 'TENT-RED']], []], $queued);
    }

    public function testAReplaceImportQueuesNothing(): void
    {
        $this->productResource->expects(self::never())->method('getProductsIdsBySkus');
        $this->catalogIngest->expects(self::never())->method('enqueueBuilt');
        $this->queue->expects(self::never())->method('enqueueMany');

        $deletes = $this->deletes();
        (new ProductImportBunch($deletes))
            ->afterGetNextUniqueBunch($this->dataSource('catalog_product', 'replace'), [['sku' => 'TENT']]);
        (new ProductImportBunchDelete($deletes))
            ->execute($this->bunchDeleted($this->dataSource('catalog_product', 'replace'), [10]));
    }

    public function testAnotherEntitysDeleteImportQueuesNothing(): void
    {
        self::assertFalse($this->deletes()->isProductDelete($this->dataSource('customer', 'delete'), null));
    }

    public function testADisconnectedEngineReadsNothingFromTheImport(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(false);
        $dataSource = $this->createMock(DataSource::class);
        $dataSource->expects(self::never())->method('getBehavior');

        $deletes = new ProductImportDelete(
            $settings,
            $this->productResource,
            $this->parentResolver,
            $this->catalogIngest,
            $this->queue
        );
        self::assertFalse($deletes->isProductDelete($dataSource, null));
    }

    private function deletes(): ProductImportDelete
    {
        return new ProductImportDelete(
            $this->settings,
            $this->productResource,
            $this->parentResolver,
            $this->catalogIngest,
            $this->queue
        );
    }

    private function dataSource(string $entity = 'catalog_product', string $behavior = 'delete'): DataSource
    {
        $dataSource = $this->createMock(DataSource::class);
        $dataSource->method('getEntityTypeCode')->willReturn($entity);
        $dataSource->method('getBehavior')->willReturn($behavior);

        return $dataSource;
    }

    /**
     * @param array<int|string> $deletedIds
     */
    private function bunchDeleted(DataSource $dataSource, array $deletedIds): Observer
    {
        $adapter = $this->createMock(ImportAdapter::class);
        $adapter->method('getDataSourceModel')->willReturn($dataSource);
        $adapter->method('getIds')->willReturn([5]);

        return new Observer(['event' => new Event(['adapter' => $adapter, 'ids_to_delete' => $deletedIds])]);
    }
}
