<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Backfill;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\EngineCatalogProcessor;
use Smaily\Connect\Model\Backfill\ImportClock;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\CatalogProductLoader;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * PRO-1352/1353: the backfill collection must be scoped to the SAME
 * canonical store as the live save path (CatalogPayloadBuilder::
 * canonicalStoreId()) — never Magento's implicit current-store resolver —
 * so price/URL/language can never disagree between the two catalog ingest
 * paths.
 *
 * CON-237: an import is its job's connection's: read at that connection's
 * canonical store view, its rows queued for it.
 */
class EngineCatalogProcessorTest extends TestCase
{
    use EngineConnectionsStub;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../Support/Stub/ProductCollectionFactory.php';
    }

    public function testBackfillCollectionIsScopedToTheCanonicalStoreBeforeLoadingPrices(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(10);

        $collection = $this->createMock(Collection::class);
        $collection->expects(self::once())->method('setStoreId')->with(7);
        $collection->method('getItems')->willReturn([$product]);

        $collectionFactory = $this->createMock(ProductCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $jobManager = $this->createMock(JobManager::class);
        // Stop cleanly after one page, exactly like an admin cancel would —
        // keeps this test to a single loadPage() call.
        $jobManager->method('isCancelled')->willReturn(true);

        $payloadBuilder = $this->createMock(CatalogPayloadBuilder::class);
        $payloadBuilder->method('canonicalStoreId')->willReturn(7);

        $ingestQueue = $this->createMock(IngestQueue::class);
        $ingestQueue->method('countPending')->willReturn(0);

        $job = $this->createJob();

        $processor = $this->processor(
            $jobManager,
            $collectionFactory,
            $payloadBuilder,
            $ingestQueue,
            $this->createMock(CatalogIngest::class)
        );

        $processor->process($job);
    }

    /**
     * PRO-1358: countProducts() (the progress-bar total) must be scoped to
     * the same canonical store as loadPage() — otherwise the total can
     * disagree with the scoped pages on multi-store installs.
     */
    public function testCountProductsAppliesTheSameCanonicalStoreScopeAsLoadPage(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::exactly(2))->method('setStoreId')->with(7);
        $collection->method('getSize')->willReturn(42);
        $collection->method('getItems')->willReturn([]);

        $collectionFactory = $this->createMock(ProductCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $jobManager = $this->createMock(JobManager::class);

        $payloadBuilder = $this->createMock(CatalogPayloadBuilder::class);
        $payloadBuilder->method('canonicalStoreId')->willReturn(7);

        $ingestQueue = $this->createMock(IngestQueue::class);
        $ingestQueue->method('countPending')->willReturn(0);

        $job = $this->createMock(Job::class);
        $job->method('getData')->willReturn(null);
        $job->method('getCursorValue')->willReturn('0');
        $job->expects(self::once())->method('setData')->with('total_count', 42);

        $processor = $this->processor(
            $jobManager,
            $collectionFactory,
            $payloadBuilder,
            $ingestQueue,
            $this->createMock(CatalogIngest::class)
        );

        $processor->process($job);
    }

    /**
     * PRO-3950: the job is taken up before the product count, so a worker
     * that dies counting leaves a running job the tick can set aside, not a
     * queued one that blocks the imports behind it.
     */
    public function testTheJobIsTakenUpBeforeTheProductCount(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('getSize')->willThrowException(new \RuntimeException('count died'));
        $collectionFactory = $this->createMock(ProductCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $job = $this->createMock(Job::class);
        $job->method('getData')->willReturn(null);

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('markRunning')->with($job);

        $ingestQueue = $this->createMock(IngestQueue::class);
        $ingestQueue->method('countPending')->willReturn(0);
        $payloadBuilder = $this->createMock(CatalogPayloadBuilder::class);

        $processor = $this->processor(
            $jobManager,
            $collectionFactory,
            $payloadBuilder,
            $ingestQueue,
            $this->createMock(CatalogIngest::class)
        );

        $this->expectExceptionMessage('count died');
        $processor->process($job);
    }

    /**
     * PRO-2506: the page and the count apply no product limitation. Each of
     * the methods below joins `catalog_product_website` or the price index
     * on one website (Magento's Product\Collection::_applyProductLimitations()
     * — addPriceData() as an INNER JOIN on price_index.website_id), which
     * drops a product that sells only on another website. Such a product
     * reaches CatalogIngest like any other, and the page selects the tax
     * class the price index used to supply.
     */
    public function testBackfillPagesEveryWebsitesProductsWithoutAWebsiteRestriction(): void
    {
        $canonicalWebsiteProduct = $this->createMock(Product::class);
        $canonicalWebsiteProduct->method('getId')->willReturn(10);
        $canonicalWebsiteProduct->method('getWebsiteIds')->willReturn([1]);
        $otherWebsiteProduct = $this->createMock(Product::class);
        $otherWebsiteProduct->method('getId')->willReturn(11);
        $otherWebsiteProduct->method('getWebsiteIds')->willReturn([2]);

        $collection = $this->createMock(Collection::class);
        foreach ([
            'addPriceData', 'addWebsiteFilter', 'addStoreFilter', 'addCategoryFilter',
            'setVisibility', 'applyFrontendPriceLimitations',
        ] as $limitation) {
            $collection->expects(self::never())->method($limitation);
        }
        $collection->expects(self::exactly(2))->method('setStoreId')->with(7);
        $collection->expects(self::once())->method('addAttributeToSelect')->with(
            self::logicalAnd(self::containsIdentical('price'), self::containsIdentical('tax_class_id'))
        );
        $collection->method('getSize')->willReturn(2);
        $collection->method('getItems')->willReturn([$canonicalWebsiteProduct, $otherWebsiteProduct]);

        $collectionFactory = $this->createMock(ProductCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->method('isCancelled')->willReturn(true);
        $jobManager->expects(self::once())->method('recordProgress')
            ->with(self::anything(), 2, 0, '11');

        $payloadBuilder = $this->createMock(CatalogPayloadBuilder::class);
        $payloadBuilder->method('canonicalStoreId')->willReturn(7);

        $ingestQueue = $this->createMock(IngestQueue::class);
        $ingestQueue->method('countPending')->willReturn(0);

        $enqueued = [];
        $catalogIngest = $this->createMock(CatalogIngest::class);
        $catalogIngest->method('enqueueProduct')->willReturnCallback(
            static function (Product $product) use (&$enqueued): bool {
                $enqueued[] = $product->getId();

                return true;
            }
        );

        $job = $this->createMock(Job::class);
        $job->method('getData')->willReturn(null);
        $job->method('getCursorValue')->willReturn('0');
        $job->expects(self::once())->method('setData')->with('total_count', 2);

        $processor = $this->processor($jobManager, $collectionFactory, $payloadBuilder, $ingestQueue, $catalogIngest);

        $processor->process($job);

        self::assertSame([10, 11], $enqueued);
    }

    /**
     * CON-237: the job of a website connection's import (website_id = its
     * key) reads at that connection's canonical store view and queues the
     * rows for that connection, with the job.
     */
    public function testAConnectionsImportIsReadAtItsStoreViewAndQueuedForIt(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(10);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::exactly(2))->method('setStoreId')->with(4);
        $collection->method('getConnection')->willReturn(
            $this->createConfiguredMock(AdapterInterface::class, ['select' => $select])
        );
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getSize')->willReturn(1);
        $collection->method('getItems')->willReturn([$product]);
        $collectionFactory = $this->createMock(ProductCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);
        $jobManager = $this->createMock(JobManager::class);
        $jobManager->method('isCancelled')->willReturn(true);
        $payloadBuilder = $this->createMock(CatalogPayloadBuilder::class);
        $payloadBuilder->method('canonicalStoreId')->willReturn(1);
        $ingestQueue = $this->createMock(IngestQueue::class);
        $catalogIngest = $this->createMock(CatalogIngest::class);
        $catalogIngest->expects(self::once())->method('enqueueProduct')
            ->with($product, 33, self::callback(static fn (Connection $connection): bool => $connection->key() === 2))
            ->willReturn(true);
        $job = $this->createMock(Job::class);
        $job->method('getId')->willReturn(33);
        $job->method('getWebsiteId')->willReturn(2);
        $job->method('getCursorValue')->willReturn('0');

        $this->processor(
            $jobManager,
            $collectionFactory,
            $payloadBuilder,
            $ingestQueue,
            $catalogIngest,
            $this->separateStores()
        )->process($job);
    }

    /**
     * CON-237: the import of a connection that is gone (its website
     * connection was disconnected) is stopped before it reads anything.
     */
    public function testTheImportOfAConnectionThatIsGoneIsStopped(): void
    {
        $collectionFactory = $this->createMock(ProductCollectionFactory::class);
        $collectionFactory->expects(self::never())->method('create');
        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::never())->method('markRunning');
        $jobManager->expects(self::once())->method('complete');
        $job = $this->createMock(Job::class);
        $job->method('getWebsiteId')->willReturn(5);
        $job->method('getData')->willReturn(null);

        $this->processor(
            $jobManager,
            $collectionFactory,
            $this->createMock(CatalogPayloadBuilder::class),
            $this->createMock(IngestQueue::class),
            $this->createMock(CatalogIngest::class),
            $this->separateStores()
        )->process($job);
    }

    private function processor(
        JobManager $jobManager,
        ProductCollectionFactory $collectionFactory,
        CatalogPayloadBuilder $payloadBuilder,
        IngestQueue $ingestQueue,
        CatalogIngest $catalogIngest,
        ?Connections $connections = null
    ): EngineCatalogProcessor {
        if ($connections === null) {
            $settings = $this->createMock(Settings::class);
            $settings->method('isConnected')->willReturn(true);
            $connections = $this->oneEngineConnection($settings);
        }

        return new EngineCatalogProcessor(
            $jobManager,
            $ingestQueue,
            $catalogIngest,
            new CatalogProductLoader($collectionFactory, $payloadBuilder),
            new ImportClock(),
            $this->engineJobConnection($connections, $jobManager)
        );
    }

    /**
     * Separate stores: the primary serves website 1, the connection of
     * website 2 (store views 4 and 5) serves website 2.
     */
    private function separateStores(): Connections
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);

        return $this->engineConnections([
            [$this->engineConnection(0, $settings, [1], [1], 1), null],
            [$this->engineConnection(2, $settings, [2], [4, 5], 4), null],
        ]);
    }

    private function createJob(): Job&MockObject
    {
        $job = $this->createMock(Job::class);
        $job->method('getData')->willReturnCallback(
            static fn (string $key): mixed => $key === 'total_count' ? 5 : null
        );
        $job->method('getCursorValue')->willReturn('0');

        return $job;
    }
}
