<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\CatalogProductLoader;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;

/**
 * PRO-1951: one Magento product save reaches three catalog hooks (product
 * save, the legacy stock item it writes on the way, and the MSI source item
 * that mirrors) — the row must be queued once, not three times, while a
 * genuinely different payload still gets its own row. This is also the one
 * place the engine-connected gate is checked for every catalog ingest path.
 *
 * PRO-1967: a stock change only records the product as changed — no product
 * load, no payload build inside the stock write's transaction; the ingest
 * flusher builds the rows in a batch, one collection load per run.
 */
class CatalogIngestTest extends TestCase
{
    private ProductCollectionFactory&MockObject $collectionFactory;
    private ProductResource&MockObject $productResource;
    private CatalogPayloadBuilder&MockObject $payloadBuilder;
    private IngestQueue&MockObject $queue;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../Support/Stub/ProductCollectionFactory.php';
    }

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(ProductCollectionFactory::class);
        $this->productResource = $this->createMock(ProductResource::class);
        $this->payloadBuilder = $this->createMock(CatalogPayloadBuilder::class);
        $this->payloadBuilder->method('canonicalStoreId')->willReturn(1);
        $this->payloadBuilder->method('isIngestible')->willReturn(true);
        $this->queue = $this->createMock(IngestQueue::class);
    }

    public function testTheSameRowSeenThroughThreeHooksIsQueuedOnce(): void
    {
        $product = $this->product(8);
        $this->payloadBuilder->method('build')->willReturn(['sku' => 'TENT', 'in_stock' => true]);
        $this->queue->expects(self::once())->method('enqueue')
            ->with(Client::DOMAIN_CATALOG, ['sku' => 'TENT', 'in_stock' => true], '8', 1);

        $ingest = $this->ingest();
        $ingest->enqueueProduct($product);
        $ingest->enqueueProduct($product);
        $ingest->enqueueProduct($product);
    }

    public function testAChangedPayloadForTheSameProductStillGetsItsOwnRow(): void
    {
        $product = $this->product(8);
        $this->payloadBuilder->method('build')->willReturnOnConsecutiveCalls(
            ['sku' => 'TENT', 'in_stock' => true],
            ['sku' => 'TENT', 'in_stock' => false]
        );
        $this->queue->expects(self::exactly(2))->method('enqueue');

        $ingest = $this->ingest();
        $ingest->enqueueProduct($product);
        $ingest->enqueueProduct($product);
    }

    public function testAProductThatLeftTheSellableSetIsTombstoned(): void
    {
        $payloadBuilder = $this->createMock(CatalogPayloadBuilder::class);
        $payloadBuilder->method('canonicalStoreId')->willReturn(1);
        $payloadBuilder->method('isIngestible')->willReturn(false);
        $payloadBuilder->method('buildTombstone')->willReturn(['sku' => 'TENT', 'in_stock' => false]);
        $this->queue->expects(self::once())->method('enqueue')
            ->with(Client::DOMAIN_CATALOG, ['sku' => 'TENT', 'in_stock' => false], '8', 1);

        $this->ingest($payloadBuilder)->enqueueProduct($this->product(8));
    }

    /**
     * The hard-delete path fires before the row is gone, so the product still
     * looks ingestible — the tombstone has to be forced.
     */
    public function testAForcedTombstoneIgnoresThatTheProductIsStillIngestible(): void
    {
        $this->payloadBuilder->expects(self::never())->method('build');
        $this->payloadBuilder->method('buildTombstone')->willReturn(['sku' => 'TENT', 'in_stock' => false]);
        $this->queue->expects(self::once())->method('enqueue')
            ->with(Client::DOMAIN_CATALOG, ['sku' => 'TENT', 'in_stock' => false], '8', 1);

        $this->ingest()->enqueueTombstone($this->product(8));
    }

    public function testAFailedBuildIsLoggedAndQueuesNothing(): void
    {
        $this->payloadBuilder->method('build')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('error');
        $this->queue->expects(self::never())->method('enqueue');

        self::assertFalse($this->ingest(null, $logger)->enqueueProduct($this->product(8)));
    }

    public function testAStockChangeOnlyRecordsTheProductAsChanged(): void
    {
        $this->collectionFactory->expects(self::never())->method('create');
        $this->payloadBuilder->expects(self::never())->method('build');
        $this->queue->expects(self::once())->method('enqueueMany')
            ->with(CatalogIngest::DOMAIN_CHANGED, [['payload' => [], 'entity_id' => '8', 'store_id' => null]])
            ->willReturn(1);

        self::assertTrue($this->ingest()->markProductChanged(8));
    }

    /**
     * A bulk source-item save: one sku lookup, one multi-row insert; a sku
     * listed twice (two order lines, two sources) is looked up once.
     */
    public function testSkusAreLookedUpWithOneQueryAndMarkedWithOneInsert(): void
    {
        $this->productResource->expects(self::once())->method('getProductsIdsBySkus')
            ->with(['TENT', 'MUG'])
            ->willReturn(['TENT' => '8', 'MUG' => '9']);
        $this->collectionFactory->expects(self::never())->method('create');
        $this->queue->expects(self::once())->method('enqueueMany')
            ->with(CatalogIngest::DOMAIN_CHANGED, [
                ['payload' => [], 'entity_id' => '8', 'store_id' => null],
                ['payload' => [], 'entity_id' => '9', 'store_id' => null],
            ])
            ->willReturn(2);

        self::assertTrue($this->ingest()->markSkusChanged(['TENT', '  ', 'MUG', 'TENT']));
    }

    public function testUnknownOrBlankSkusRecordNothing(): void
    {
        $this->productResource->method('getProductsIdsBySkus')->willReturn([]);
        $this->queue->expects(self::never())->method('enqueueMany');

        self::assertFalse($this->ingest()->markSkusChanged(['GONE']));
        self::assertFalse($this->ingest()->markSkusChanged(['  ']));
        self::assertFalse($this->ingest()->markProductChanged(0));
    }

    /**
     * The gate every catalog hook used to repeat now lives here, once — and
     * it short-circuits before anything is read.
     */
    public function testADisconnectedEngineNeverBuildsLoadsOrQueues(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(false);
        $this->productResource->expects(self::never())->method('getProductsIdsBySkus');
        $this->payloadBuilder->expects(self::never())->method('build');
        $this->queue->expects(self::never())->method('enqueue');
        $this->queue->expects(self::never())->method('enqueueMany');
        $this->collectionFactory->expects(self::never())->method('create');

        $ingest = $this->ingest(null, null, $settings);
        self::assertFalse($ingest->enqueueProduct($this->product(8)));
        self::assertFalse($ingest->enqueueTombstone($this->product(8)));
        self::assertFalse($ingest->markProductChanged(8));
        self::assertFalse($ingest->markSkusChanged(['TENT']));
    }

    public function testNoChangedProductsMeansNoProductLoad(): void
    {
        $this->queue->method('claimBatch')->willReturn([]);
        $this->collectionFactory->expects(self::never())->method('create');
        $this->queue->expects(self::never())->method('enqueueChangedPayloads');

        $this->ingest()->buildChanged();
    }

    /**
     * Repeated markers for one product collapse into one build; the batch is
     * loaded as one canonical-scope collection, handed to the queue in one
     * call (which leaves out a row identical to the product's newest unsent
     * row), and the markers are removed.
     */
    public function testChangedProductsAreBuiltAsOneBatch(): void
    {
        $markers = [$this->marker(1, '8'), $this->marker(2, '9'), $this->marker(3, '8')];
        $this->queue->method('claimBatch')->with(CatalogIngest::DOMAIN_CHANGED, 100)->willReturn($markers);
        $collection = $this->collection([$this->product(8), $this->product(9)]);
        $collection->expects(self::once())->method('setStoreId')->with(1);
        $collection->expects(self::once())->method('addAttributeToSelect')
            ->with(CatalogPayloadBuilder::PRODUCT_ATTRIBUTES);
        $collection->expects(self::once())->method('addFieldToFilter')->with('entity_id', ['in' => [8, 9]]);
        $collection->expects(self::once())->method('addUrlRewrite');
        $this->collectionFactory->expects(self::once())->method('create')->willReturn($collection);
        $this->payloadBuilder->method('build')->willReturnCallback(
            static fn (Product $product): array => ['sku' => 'SKU-' . $product->getId()]
        );
        $this->queue->expects(self::once())->method('enqueueChangedPayloads')->with(Client::DOMAIN_CATALOG, [
            '8' => ['sku' => 'SKU-8'],
            '9' => ['sku' => 'SKU-9'],
        ], 1);
        $this->queue->expects(self::once())->method('delete')->with($markers);

        $this->ingest()->buildChanged();
    }

    /**
     * A product deleted between the marker and the build is simply gone: the
     * delete observer already told the engine; the marker is dropped.
     */
    public function testAProductDeletedSinceItsMarkerIsDroppedQuietly(): void
    {
        $markers = [$this->marker(1, '7')];
        $this->queue->method('claimBatch')->willReturn($markers);
        $this->collectionFactory->method('create')->willReturn($this->collection([]));
        $this->queue->expects(self::never())->method('enqueueChangedPayloads');
        $this->queue->expects(self::once())->method('delete')->with($markers);

        $this->ingest()->buildChanged();
    }

    public function testAChangedProductThatLeftTheSellableSetIsTombstoned(): void
    {
        $payloadBuilder = $this->createMock(CatalogPayloadBuilder::class);
        $payloadBuilder->method('canonicalStoreId')->willReturn(1);
        $payloadBuilder->method('isIngestible')->willReturn(false);
        $payloadBuilder->method('buildTombstone')->willReturn(['sku' => 'TENT', 'in_stock' => false]);
        $this->queue->method('claimBatch')->willReturn([$this->marker(1, '8')]);
        $this->collectionFactory->method('create')->willReturn($this->collection([$this->product(8)]));
        $this->queue->expects(self::once())->method('enqueueChangedPayloads')->with(Client::DOMAIN_CATALOG, [
            '8' => ['sku' => 'TENT', 'in_stock' => false],
        ], 1);

        $this->ingest($payloadBuilder)->buildChanged();
    }

    public function testAFailedBuildInTheBatchIsLoggedAndTheRestStillQueue(): void
    {
        $this->queue->method('claimBatch')->willReturn([$this->marker(1, '8'), $this->marker(2, '9')]);
        $this->collectionFactory->method('create')
            ->willReturn($this->collection([$this->product(8), $this->product(9)]));
        $this->payloadBuilder->method('build')->willReturnCallback(
            static function (Product $product): array {
                if ((int)$product->getId() === 8) {
                    throw new \RuntimeException('boom');
                }

                return ['sku' => 'SKU-9'];
            }
        );
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('error');
        $this->queue->expects(self::once())->method('enqueueChangedPayloads')->with(Client::DOMAIN_CATALOG, [
            '9' => ['sku' => 'SKU-9'],
        ], 1);
        $this->queue->expects(self::once())->method('delete');

        $this->ingest(null, $logger)->buildChanged();
    }

    /**
     * PRO-3768: a product import's Delete builds the tombstones before the
     * products are gone — one collection, forced tombstones (the products
     * still look sellable), queued later as built.
     */
    public function testTombstonesAreBuiltAsOneBatchAndQueuedLater(): void
    {
        $collection = $this->collection([$this->product(8), $this->product(9)]);
        $collection->expects(self::once())->method('addFieldToFilter')->with('entity_id', ['in' => [8, 9]]);
        $this->collectionFactory->expects(self::once())->method('create')->willReturn($collection);
        $this->payloadBuilder->expects(self::never())->method('build');
        $this->payloadBuilder->method('buildTombstone')->willReturnCallback(
            static fn (Product $product): array => ['sku' => 'SKU-' . $product->getId(), 'in_stock' => false]
        );
        $rows = [
            8 => ['sku' => 'SKU-8', 'in_stock' => false],
            9 => ['sku' => 'SKU-9', 'in_stock' => false],
        ];
        $this->queue->expects(self::once())->method('enqueueChangedPayloads')
            ->with(Client::DOMAIN_CATALOG, $rows, 1);

        $ingest = $this->ingest();
        $built = $ingest->buildTombstones([8, 9]);
        self::assertSame($rows, $built);
        $ingest->enqueueBuilt($built);
    }

    public function testNoTombstonesMeansNoProductLoadAndNoInsert(): void
    {
        $this->collectionFactory->expects(self::never())->method('create');
        $this->queue->expects(self::never())->method('enqueueChangedPayloads');

        $ingest = $this->ingest();
        self::assertSame([], $ingest->buildTombstones([]));
        $ingest->enqueueBuilt([]);
    }

    /**
     * §3b (PRO-1231): one catalog_remove row per product, carrying the raw
     * entity id, for the admin delete and the import's Delete alike.
     */
    public function testRemovalsAreQueuedAsOneSection3bRowPerProduct(): void
    {
        $this->queue->expects(self::once())->method('enqueueMany')->with(Client::DOMAIN_CATALOG_REMOVE, [
            ['payload' => ['product_id' => '42'], 'entity_id' => '42', 'store_id' => null],
            ['payload' => ['product_id' => '43'], 'entity_id' => '43', 'store_id' => null],
        ]);

        $this->ingest()->enqueueRemovals([42, 43]);
    }

    private function ingest(
        ?CatalogPayloadBuilder $payloadBuilder = null,
        ?Logger $logger = null,
        ?Settings $settings = null
    ): CatalogIngest {
        if ($settings === null) {
            $settings = $this->createMock(Settings::class);
            $settings->method('isConnected')->willReturn(true);
        }

        $payloadBuilder ??= $this->payloadBuilder;

        return new CatalogIngest(
            $settings,
            new CatalogProductLoader($this->collectionFactory, $payloadBuilder),
            $this->productResource,
            $payloadBuilder,
            $this->queue,
            $logger ?? $this->createMock(Logger::class)
        );
    }

    /**
     * @param Product[] $products
     */
    private function collection(array $products): Collection&MockObject
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('addUrlRewrite')->willReturnSelf();
        $collection->method('getItems')->willReturn($products);

        return $collection;
    }

    private function marker(int $id, string $productId): IngestEvent&MockObject
    {
        $marker = $this->createMock(IngestEvent::class);
        $marker->method('getId')->willReturn($id);
        $marker->method('getEntityId')->willReturn($productId);

        return $marker;
    }

    private function product(int $id): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);

        return $product;
    }
}
