<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Engine;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\CatalogImportExport\Model\Import\Product as ImportAdapter;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\ImportExport\Model\ResourceModel\Import\Data as ImportData;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\CatalogProductLoader;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Payload\ParentProductResolver;
use Smaily\Connect\Model\Engine\ProductImportDelete;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Observer\Engine\ProductImportBunchDelete;
use Smaily\Connect\Plugin\Engine\ProductImportBunch;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * PRO-3768 against a real MySQL: Magento's import data source (the real
 * Import\Data resource model on an importexport_importdata mirror) and the
 * steps of Import\Product::_deleteProducts() — read the bunch, DELETE the
 * products in a transaction, fire catalog_product_import_bunch_delete_commit_before,
 * commit. The deleted products' removal lands in the real ingest queue: §3b
 * for the standalone product, the per-SKU tombstone for the configurable
 * child (its parent link is gone after the DELETE, so it is found while the
 * bunch is read). A delete that rolls back queues nothing; a Replace import
 * queues nothing. The tombstone row itself is CatalogPayloadBuilder's
 * (stubbed: the harness has no EAV catalog).
 */
class ProductImportDeleteTest extends IntegrationTestCase
{
    private const TABLES = ['catalog_product_super_link', 'catalog_product_entity', 'importexport_importdata'];
    private const TOMBSTONE = ['sku' => 'TENT-RED', 'in_stock' => false];

    private ImportData $importData;

    private ProductImportDelete $productImportDelete;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropTables();
        $this->connection->query(
            'CREATE TABLE `catalog_product_entity` (`entity_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `sku` VARCHAR(64) NOT NULL, PRIMARY KEY (`entity_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->connection->query(
            'CREATE TABLE `catalog_product_super_link` (`link_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `product_id` INT UNSIGNED NOT NULL, `parent_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`link_id`),'
            . ' CONSTRAINT `SUPER_LINK_PRODUCT` FOREIGN KEY (`product_id`)'
            . ' REFERENCES `catalog_product_entity` (`entity_id`) ON DELETE CASCADE)'
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->connection->query(
            'CREATE TABLE `importexport_importdata` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `entity` VARCHAR(50) NOT NULL, `behavior` VARCHAR(10) NOT NULL DEFAULT \'append\','
            . ' `data` LONGTEXT NULL, `is_processed` TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (`id`))'
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->connection->insertMultiple('catalog_product_entity', [
            ['entity_id' => 10, 'sku' => 'TENT'],
            ['entity_id' => 11, 'sku' => 'TENT-RED'],
            ['entity_id' => 12, 'sku' => 'TENT-BLUE'],
            ['entity_id' => 20, 'sku' => 'LAMP'],
        ]);
        $this->connection->insertMultiple('catalog_product_super_link', [
            ['product_id' => 11, 'parent_id' => 10],
            ['product_id' => 12, 'parent_id' => 10],
        ]);

        // The json helper only decodes the stored bunch; its own dependency
        // graph (URL encoder, request) is not in the harness.
        $jsonHelper = $this->createMock(JsonHelper::class);
        $jsonHelper->method('jsonDecode')->willReturnCallback(
            static fn (string $json): mixed => json_decode($json, true)
        );
        $this->importData = $this->objectManager->create(ImportData::class, ['jsonHelper' => $jsonHelper]);
        $this->productImportDelete = $this->productImportDelete();
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function testDeletedProductsGetTheRemovalAProductDeleteGives(): void
    {
        $this->importBunch('delete', [['sku' => 'TENT-RED'], ['sku' => 'LAMP']]);

        $this->runDelete();

        self::assertSame(['TENT', 'TENT-BLUE'], $this->remainingSkus());
        $rows = $this->fetchAll(IngestEventResource::TABLE_NAME);
        self::assertSame(
            [
                [Client::DOMAIN_CATALOG, '11', self::TOMBSTONE],
                [Client::DOMAIN_CATALOG_REMOVE, '20', ['product_id' => '20']],
            ],
            array_map(
                static fn (array $row): array => [
                    $row['domain'],
                    $row['entity_id'],
                    json_decode((string)$row['payload'], true),
                ],
                $rows
            )
        );
    }

    public function testADeleteThatRollsBackQueuesNothing(): void
    {
        $this->importBunch('delete', [['sku' => 'LAMP']]);

        $this->runDelete(rollBack: true);

        self::assertSame(['LAMP', 'TENT', 'TENT-BLUE', 'TENT-RED'], $this->remainingSkus());
        self::assertSame([], $this->fetchAll(IngestEventResource::TABLE_NAME));
    }

    /**
     * Replace deletes through the same code and creates the products again.
     */
    public function testAReplaceImportQueuesNothing(): void
    {
        $this->importBunch('replace', [['sku' => 'TENT-RED'], ['sku' => 'LAMP']]);

        $this->runDelete();

        self::assertSame([], $this->fetchAll(IngestEventResource::TABLE_NAME));
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    private function importBunch(string $behavior, array $rows): void
    {
        $this->connection->insert('importexport_importdata', [
            'entity' => 'catalog_product',
            'behavior' => $behavior,
            'data' => json_encode($rows),
        ]);
    }

    /**
     * The steps of Import\Product::_deleteProducts(), with the plugin on the
     * bunch read and the observer on the in-transaction event.
     */
    private function runDelete(bool $rollBack = false): void
    {
        $ids = array_map('intval', $this->connection->fetchCol('SELECT id FROM importexport_importdata'));
        $adapter = $this->createMock(ImportAdapter::class);
        $adapter->method('getDataSourceModel')->willReturn($this->importData);
        $adapter->method('getIds')->willReturn($ids);
        $plugin = new ProductImportBunch($this->productImportDelete);
        $observer = new ProductImportBunchDelete($this->productImportDelete);

        while ($bunch = $plugin->afterGetNextUniqueBunch(
            $this->importData,
            $this->importData->getNextUniqueBunch($ids),
            $ids
        )) {
            $skus = array_column($bunch, 'sku');
            $idsToDelete = array_map('intval', $this->connection->fetchCol(
                $this->connection->select()->from('catalog_product_entity', ['entity_id'])->where('sku IN (?)', $skus)
            ));
            $this->connection->beginTransaction();
            $this->connection->delete('catalog_product_entity', ['entity_id IN (?)' => $idsToDelete]);
            $observer->execute(new Observer(['event' => new Event([
                'adapter' => $adapter,
                'bunch' => $bunch,
                'ids_to_delete' => $idsToDelete,
            ])]));
            $rollBack ? $this->connection->rollBack() : $this->connection->commit();
        }
    }

    private function productImportDelete(): ProductImportDelete
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);

        $productResource = $this->createMock(ProductResource::class);
        $productResource->method('getProductsIdsBySkus')->willReturnCallback(
            fn (array $skus): array => $this->connection->fetchPairs(
                $this->connection->select()->from('catalog_product_entity', ['sku', 'entity_id'])
                    ->where('sku IN (?)', $skus)
            )
        );

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $metadataPool = $this->createMock(MetadataPool::class);
        $metadataPool->method('getMetadata')->with(ProductInterface::class)->willReturn($metadata);
        $parentResolver = $this->objectManager->create(ParentProductResolver::class, ['metadataPool' => $metadataPool]);

        $productLoader = $this->createMock(CatalogProductLoader::class);
        $productLoader->method('load')->willReturnCallback(fn (): array => [$this->product(11)]);
        $payloadBuilder = $this->createMock(CatalogPayloadBuilder::class);
        $payloadBuilder->method('canonicalStoreId')->willReturn(1);
        $payloadBuilder->method('buildTombstone')->willReturn(self::TOMBSTONE);
        $ingestQueue = $this->objectManager->create(IngestQueue::class);

        return new ProductImportDelete(
            $settings,
            $productResource,
            $parentResolver,
            new CatalogIngest(
                $settings,
                $productLoader,
                $productResource,
                $payloadBuilder,
                $ingestQueue,
                $this->createMock(Logger::class)
            ),
            $ingestQueue
        );
    }

    private function product(int $id): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);

        return $product;
    }

    /**
     * @return string[]
     */
    private function remainingSkus(): array
    {
        return $this->connection->fetchCol('SELECT sku FROM catalog_product_entity ORDER BY sku');
    }

    private function dropTables(): void
    {
        foreach (self::TABLES as $table) {
            $this->connection->query('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }
}
