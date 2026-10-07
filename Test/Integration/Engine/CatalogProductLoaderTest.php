<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Engine;

use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\Indexer\Product\Flat\State as FlatState;
use Magento\Catalog\Model\Indexer\Product\Price\PriceTableResolver;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Gallery\ReadHandler as GalleryReadHandler;
use Magento\Catalog\Model\Product\OptionFactory;
use Magento\Catalog\Model\ResourceModel\Category;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Helper as CatalogResourceHelper;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\Collection\ProductLimitationFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Catalog\Model\ResourceModel\Url as CatalogUrl;
use Magento\CatalogUrlRewrite\Model\Storage\DbStorage;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Backend\DefaultBackend;
use Magento\Eav\Model\EntityFactory as EavEntityFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategy\Query as FetchStrategyQuery;
use Magento\Framework\Data\Collection\EntityFactory;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\DimensionFactory;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\Validator\UniversalFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Smaily\Connect\Model\Engine\CatalogProductLoader;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * PRO-3854: the nightly manifest's page against a real MySQL — Magento's
 * own product collection code builds the select on Magento's own
 * catalog_product_entity(_int) tables. Only the EAV metadata (the status
 * and visibility attributes, the product entity) is described by mocks;
 * the harness has no EAV configuration.
 *
 * The status filter reads the canonical store view's value, falling back
 * to the default one — the value CatalogPayloadBuilder::manifestItem()
 * reads — so a product disabled on that store view only stays out of the
 * list, as it did when manifestItem() alone left it out.
 */
class CatalogProductLoaderTest extends IntegrationTestCase
{
    private const CANONICAL_STORE = 1;
    private const OTHER_STORE = 2;
    private const STATUS_ID = 97;
    private const VISIBILITY_ID = 99;

    private SchemaInstaller $schema;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Support/Stub/ProductCollectionFactory.php';
        require_once __DIR__ . '/../Support/Stub/EavEntityFactory.php';
        require_once __DIR__ . '/../Support/Stub/ProductOptionFactory.php';
        require_once __DIR__ . '/../Support/Stub/ProductLimitationFactory.php';
        $this->schema = new SchemaInstaller($this->connection);
        $this->schema->createCatalogProductTables();
    }

    protected function tearDown(): void
    {
        $this->schema->dropCatalogProductTables();
        parent::tearDown();
    }

    public function testTheManifestPageLeavesOutWhatIsDisabledAtTheCanonicalStoreView(): void
    {
        $enabled = Status::STATUS_ENABLED;
        $disabled = Status::STATUS_DISABLED;
        $this->product(1, [0 => $enabled]);
        $this->product(2, [0 => $enabled, self::CANONICAL_STORE => $disabled]);
        $this->product(3, [0 => $disabled, self::CANONICAL_STORE => $enabled]);
        $this->product(4, [0 => $disabled]);
        $this->product(5, [0 => $enabled, self::OTHER_STORE => $disabled], [self::CANONICAL_STORE => 1]);

        $page = $this->loader()->loadForManifest(0, 1000);

        self::assertSame([1, 3, 5], $this->ids($page), 'disabled on the canonical store view: 2; by default only: 4');
        foreach ($page as $product) {
            self::assertSame($enabled, (int)$product->getData('status'), 'the store view value manifestItem() reads');
        }
        self::assertSame(
            [4, 4, 1],
            array_map(static fn (Product $product): int => (int)$product->getVisibility(), $page),
            'the selected attributes are read at the canonical store view too'
        );
    }

    public function testTheManifestPageIsOnePageByEntityId(): void
    {
        foreach ([1, 2, 3, 4] as $id) {
            $this->product($id, [0 => $id === 2 ? Status::STATUS_DISABLED : Status::STATUS_ENABLED]);
        }

        self::assertSame([3, 4], $this->ids($this->loader()->loadForManifest(1, 2)));
        self::assertSame([4], $this->ids($this->loader()->loadForManifest(3, 2)));
        self::assertSame([], $this->ids($this->loader()->loadForManifest(4, 2)));
    }

    /**
     * @param array<int, int> $statusByStore
     * @param array<int, int> $visibilityByStore store view values over the default 4 (catalog, search)
     */
    private function product(int $id, array $statusByStore, array $visibilityByStore = []): void
    {
        $this->connection->insert('catalog_product_entity', [
            'entity_id' => $id,
            'attribute_set_id' => 4,
            'sku' => 'SKU-' . $id,
        ]);
        foreach ($statusByStore as $storeId => $status) {
            $this->attributeValue($id, self::STATUS_ID, $storeId, $status);
        }
        foreach ([0 => 4] + $visibilityByStore as $storeId => $visibility) {
            $this->attributeValue($id, self::VISIBILITY_ID, $storeId, $visibility);
        }
    }

    private function attributeValue(int $productId, int $attributeId, int $storeId, int $value): void
    {
        $this->connection->insert('catalog_product_entity_int', [
            'attribute_id' => $attributeId,
            'store_id' => $storeId,
            'entity_id' => $productId,
            'value' => $value,
        ]);
    }

    /**
     * @param Product[] $page
     * @return int[]
     */
    private function ids(array $page): array
    {
        return array_map(static fn (Product $product): int => (int)$product->getId(), $page);
    }

    private function loader(): CatalogProductLoader
    {
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturnCallback(fn (): ProductCollection => $this->collection());
        $payloadBuilder = $this->createMock(CatalogPayloadBuilder::class);
        $payloadBuilder->method('canonicalStoreId')->willReturn(self::CANONICAL_STORE);

        return new CatalogProductLoader($collectionFactory, $payloadBuilder);
    }

    /**
     * Magento's product collection, built by its own constructor; the EAV
     * entity and attribute metadata are mocks, the select and the tables
     * are real.
     */
    private function collection(): ProductCollection
    {
        $attributes = [
            'status' => $this->intAttribute('status', self::STATUS_ID),
            'visibility' => $this->intAttribute('visibility', self::VISIBILITY_ID),
        ];
        $attributesById = [
            self::STATUS_ID => $attributes['status'],
            self::VISIBILITY_ID => $attributes['visibility'],
        ];
        $find = static fn ($code): Attribute|false => $attributes[$code] ?? $attributesById[(int)$code] ?? false;

        $entity = $this->createMock(ProductResource::class);
        $entity->method('getConnection')->willReturn($this->connection);
        $entity->method('getType')->willReturn(Product::ENTITY);
        $entity->method('getEntityTable')->willReturn('catalog_product_entity');
        $entity->method('getEntityIdField')->willReturn('entity_id');
        $entity->method('getLinkField')->willReturn('entity_id');
        $entity->method('getDefaultAttributes')->willReturn(
            ['entity_id', 'attribute_set_id', 'type_id', 'sku', 'created_at', 'updated_at']
        );
        $entity->method('getTable')->willReturnArgument(0);
        $entity->method('getAttribute')->willReturnCallback($find);
        $entity->method('isAttributeStatic')->willReturnCallback(
            static fn (string $code): bool => !isset($attributes[$code])
        );
        $universalFactory = $this->createMock(UniversalFactory::class);
        $universalFactory->method('create')->willReturn($entity);
        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturnCallback(
            static fn ($entityType, $code): Attribute|false => $find($code)
        );
        $entityFactory = $this->createMock(EntityFactory::class);
        $entityFactory->method('create')->willReturn($this->productPrototype());
        $flatState = $this->createMock(FlatState::class);
        $flatState->method('isAvailable')->willReturn(false);
        // Magento's implicit current store, which the collection holds until
        // the loader sets the canonical one: another store view, so a page
        // read at it would differ.
        $currentStore = $this->createMock(Store::class);
        $currentStore->method('getId')->willReturn(self::OTHER_STORE);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($currentStore);
        $resource = $this->objectManager->get(ResourceConnection::class);

        return new ProductCollection(
            $entityFactory,
            $this->createMock(LoggerInterface::class),
            new FetchStrategyQuery(),
            $this->createMock(EventManager::class),
            $eavConfig,
            $resource,
            $this->createMock(EavEntityFactory::class),
            new CatalogResourceHelper($resource),
            $universalFactory,
            $storeManager,
            $this->createMock(ModuleManager::class),
            $flatState,
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(OptionFactory::class),
            $this->createMock(CatalogUrl::class),
            $this->createMock(TimezoneInterface::class),
            $this->createMock(CustomerSession::class),
            $this->createMock(DateTime::class),
            $this->createMock(GroupManagementInterface::class),
            $this->connection,
            new ProductLimitationFactory(),
            $this->createMock(MetadataPool::class),
            $this->createMock(TableMaintainer::class),
            $this->createMock(PriceTableResolver::class),
            $this->createMock(DimensionFactory::class),
            $this->createMock(Category::class),
            $this->createMock(DbStorage::class),
            $this->createMock(GalleryReadHandler::class),
            $this->createMock(Gallery::class)
        );
    }

    /**
     * A website-scope int attribute (status and visibility both are).
     */
    private function intAttribute(string $code, int $id): Attribute
    {
        $backend = $this->createMock(DefaultBackend::class);
        $backend->method('isStatic')->willReturn(false);
        $backend->method('getTable')->willReturn('catalog_product_entity_int');
        $attribute = $this->createMock(Attribute::class);
        $attribute->method('getId')->willReturn($id);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('isStatic')->willReturn(false);
        $attribute->method('getBackend')->willReturn($backend);
        $attribute->method('getBackendTable')->willReturn('catalog_product_entity_int');
        $attribute->method('getBackendType')->willReturn('int');
        $attribute->method('isScopeGlobal')->willReturn(false);

        return $attribute;
    }

    /**
     * The item every row is loaded into: a product that needs none of the
     * services a full one is built with.
     */
    private function productPrototype(): Product
    {
        return new class extends Product {
            public function __construct()
            {
                $this->_idFieldName = 'entity_id';
            }

            /**
             * @inheritDoc
             */
            public function isVisibleInSiteVisibility()
            {
                return true;
            }
        };
    }
}
