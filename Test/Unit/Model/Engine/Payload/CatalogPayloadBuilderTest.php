<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine\Payload;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Helper\ImageFactory as ImageHelperFactory;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\App\Area;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Payload\ParentProductResolver;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\StorefrontScript;
use Smaily\Connect\Model\StorefrontUrl;

/**
 * PRO-1231: every catalog row (upsert and tombstone alike) carries the §3
 * identity tag `tags.product_id` — the platform parent product id §3b
 * removal matches on — while the `sku` keying stays untouched (PRO-1267).
 */
class CatalogPayloadBuilderTest extends TestCase
{
    private const IMAGE_URL = 'https://backend.example.com/media/catalog/product/o/a/oak.jpg';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../Support/Stub/ImageFactory.php';
    }

    public function testBuildEmitsParentProductIdTagWithoutChangingSkuKeying(): void
    {
        $builder = $this->createBuilder('17');

        $item = $builder->build($this->product(42, 'SHIRT-S'));

        self::assertSame('SHIRT-S', $item['sku'], 'sku keying must stay untouched (PRO-1267)');
        self::assertSame('42', $item['external_id']);
        self::assertSame('17', $item['tags']['product_id'], 'configurable child carries the PARENT id');
        self::assertSame('uncategorized', $item['tags']['category_path']);
    }

    public function testStandaloneProductTagsItsOwnEntityId(): void
    {
        $builder = $this->createBuilder('42');

        $item = $builder->build($this->product(42, 'SHIRT'));

        self::assertSame('42', $item['tags']['product_id']);
    }

    public function testTombstonePayloadKeepsTheProductIdTag(): void
    {
        $builder = $this->createBuilder('17');

        $item = $builder->buildTombstone($this->product(42, 'SHIRT-S'));

        self::assertFalse($item['in_stock']);
        self::assertSame('17', $item['tags']['product_id']);
    }

    /**
     * PRO-1269: product_url must be generated under frontend store emulation
     * so it is the clean storefront URL in any execution context (web/CLI/
     * cron) — never one embedding the invoking PHP entry script path. The
     * emulation is forced to the frontend area and always stopped (once for
     * the product link, once for the image link — PRO-3731).
     */
    public function testProductUrlIsBuiltUnderFrontendStoreEmulation(): void
    {
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects(self::exactly(2))
            ->method('startEnvironmentEmulation')
            ->with(self::anything(), Area::AREA_FRONTEND, true);
        $emulation->expects(self::exactly(2))->method('stopEnvironmentEmulation');

        $builder = $this->createBuilder('42', $emulation);

        $item = $builder->build($this->product(42, 'SHIRT'));

        self::assertSame('https://shop.example/shirt', $item['product_url']);
    }

    /**
     * PRO-3660: a store with a separate storefront sends each product link
     * on the storefront's address — the path and the query string stay —
     * and its image links unchanged.
     */
    public function testProductLinkStartsWithTheStorefrontUrlAndKeepsPathAndQuery(): void
    {
        $builder = $this->createBuilder(
            '42',
            storefrontUrl: 'https://shop.example.com',
            imageHelperFactory: $this->imageHelperFactory(self::IMAGE_URL)
        );

        $item = $builder->build($this->product(
            42,
            'OAK',
            productUrl: 'http://backend.example.com:8080/oak-table.html?___store=en&a=1'
        ));

        self::assertSame('https://shop.example.com/oak-table.html?___store=en&a=1', $item['product_url']);
        self::assertSame(self::IMAGE_URL, $item['image_url']);
    }

    public function testWithoutAStorefrontUrlTheProductAndImageLinksAreMagentosOwn(): void
    {
        $builder = $this->createBuilder('42', imageHelperFactory: $this->imageHelperFactory(self::IMAGE_URL));

        $item = $builder->build($this->product(42, 'OAK', productUrl: 'https://backend.example.com/oak-table.html?x=1'));

        self::assertSame('https://backend.example.com/oak-table.html?x=1', $item['product_url']);
        self::assertSame(self::IMAGE_URL, $item['image_url']);
    }

    /**
     * PRO-1352/1353: price must always come from the ONE canonical store
     * scope (the default store view), never whatever scope the caller
     * happened to load the product in (e.g. an admin edit under a
     * non-default store view) — otherwise the same product can ingest a
     * different price depending on who saved it last.
     */
    public function testPriceIsResolvedUnderTheCanonicalStoreScopeNotWhateverScopeTheProductWasLoadedIn(): void
    {
        $canonicalStore = $this->createMock(StoreInterface::class);
        $canonicalStore->method('getId')->willReturn(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([]);
        $storeManager->method('getDefaultStoreView')->willReturn($canonicalStore);

        $loadedProduct = $this->product(42, 'SHIRT', 19.99);
        $loadedProduct->method('getStoreId')->willReturn(5); // admin edited store view 5, not canonical

        $scopedProduct = $this->product(42, 'SHIRT', 29.99); // canonical scope has a different price
        $scopedProduct->method('getStoreId')->willReturn(1);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->expects(self::once())
            ->method('getById')
            ->with(42, false, 1)
            ->willReturn($scopedProduct);

        $parentResolver = $this->createMock(ParentProductResolver::class);
        $parentResolver->method('productIdOf')->willReturn('42');

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(true);
        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItem')->willReturn($stockItem);

        $builder = new CatalogPayloadBuilder(
            $storeManager,
            $productRepository,
            $this->createMock(CategoryRepositoryInterface::class),
            $stockRegistry,
            $this->createMock(StockRegistryStorage::class),
            new ImageHelperFactory(),
            $this->createMock(LanguageResolver::class),
            $parentResolver,
            $this->createMock(Emulation::class),
            $this->storefrontUrl(''),
            new StorefrontScript($storeManager, $this->createMock(Http::class))
        );

        $item = $builder->build($loadedProduct);

        self::assertSame(29.99, $item['price']);
        self::assertSame(1, $builder->canonicalStoreId());
    }

    /**
     * PRO-1352/1353: when the product is already loaded at the canonical
     * scope (the backfill collection sets this explicitly), price reads
     * straight off it — no extra repository reload.
     */
    public function testPriceSkipsTheReloadWhenTheProductIsAlreadyAtTheCanonicalScope(): void
    {
        $canonicalStore = $this->createMock(StoreInterface::class);
        $canonicalStore->method('getId')->willReturn(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([]);
        $storeManager->method('getDefaultStoreView')->willReturn($canonicalStore);

        $product = $this->product(42, 'SHIRT', 19.99);
        $product->method('getStoreId')->willReturn(1);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->expects(self::never())->method('getById');

        $parentResolver = $this->createMock(ParentProductResolver::class);
        $parentResolver->method('productIdOf')->willReturn('42');

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(true);
        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItem')->willReturn($stockItem);

        $builder = new CatalogPayloadBuilder(
            $storeManager,
            $productRepository,
            $this->createMock(CategoryRepositoryInterface::class),
            $stockRegistry,
            $this->createMock(StockRegistryStorage::class),
            new ImageHelperFactory(),
            $this->createMock(LanguageResolver::class),
            $parentResolver,
            $this->createMock(Emulation::class),
            $this->storefrontUrl(''),
            new StorefrontScript($storeManager, $this->createMock(Http::class))
        );

        $item = $builder->build($product);

        self::assertSame(19.99, $item['price']);
    }

    /**
     * PRO-1762 (contract v1.7.0 §3): every catalog row carries the currency
     * the price we send is actually denominated in — the canonical store's
     * DISPLAY currency, which is what Magento's price readers convert into,
     * not the base currency the amount is stored in.
     */
    public function testCatalogRowCarriesTheCanonicalStoresDisplayCurrency(): void
    {
        $canonicalStore = $this->createMock(Store::class);
        $canonicalStore->method('getId')->willReturn(1);
        $canonicalStore->method('getBaseCurrencyCode')->willReturn('EUR');
        $canonicalStore->method('getDefaultCurrencyCode')->willReturn('USD');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([]);
        $storeManager->method('getDefaultStoreView')->willReturn($canonicalStore);

        $product = $this->product(42, 'SHIRT');
        $product->method('getStoreId')->willReturn(1);

        $builder = $this->createBuilder('42', null, $storeManager);

        self::assertSame('USD', $builder->build($product)['currency']);
        self::assertSame('USD', $builder->buildTombstone($product)['currency']);
    }

    /**
     * PRO-1762: an unresolvable store falls back to the contract default
     * rather than sending an empty currency.
     */
    public function testCurrencyFallsBackToTheContractDefaultWhenNoStoreResolves(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([]);
        $storeManager->method('getDefaultStoreView')->willReturn(null);

        $item = $this->createBuilder('42', null, $storeManager)->build($this->product(42, 'SHIRT'));

        self::assertSame('EUR', $item['currency']);
    }

    /**
     * PRO-1951: the stock registry memoises the item per request and MSI
     * mirrors onto the legacy row with direct SQL, so the memo is dropped
     * here — at the only read — rather than in one caller. Every path (live
     * hooks, the delete tombstone, backfill and the nightly re-sync) is then
     * correct by construction.
     */
    public function testTheStockRegistryMemoIsDroppedAtTheOnlyStockRead(): void
    {
        $storage = $this->createMock(StockRegistryStorage::class);
        $storage->expects(self::once())->method('removeStockItem')->with(42);

        $item = $this->createBuilder('42', null, null, $storage)->build($this->product(42, 'SHIRT'));

        self::assertTrue($item['in_stock']);
    }

    /**
     * PRO-1458: a product that is not assigned to the default website has no
     * price of its own at the canonical scope, so price and URL are read at
     * the default store view of the first website it IS assigned to.
     */
    public function testProductOutsideTheDefaultWebsiteIsPricedAtAWebsiteItBelongsTo(): void
    {
        $websiteStore = $this->createMock(Store::class);
        $websiteStore->method('getId')->willReturn(7);
        $website = $this->createMock(Website::class);
        $website->method('getDefaultStore')->willReturn($websiteStore);

        $storeManager = $this->canonicalStoreManager();
        $storeManager->expects(self::once())->method('getWebsite')->with(2)->willReturn($website);

        $loadedProduct = $this->product(42, 'SHIRT', 19.99, [2]);
        $loadedProduct->method('getStoreId')->willReturn(1); // loaded at the canonical scope

        // website 2 prices and links it differently
        $scopedProduct = $this->product(42, 'SHIRT', 29.99, [2], 'https://second.example/shirt');
        $scopedProduct->method('getStoreId')->willReturn(7);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->expects(self::once())
            ->method('getById')
            ->with(42, false, 7)
            ->willReturn($scopedProduct);

        $emulation = $this->createMock(Emulation::class);
        $emulation->expects(self::exactly(2)) // the product link and the image link
            ->method('startEnvironmentEmulation')
            ->with(7, Area::AREA_FRONTEND, true);

        $item = $this->createBuilder('42', $emulation, $storeManager, null, $productRepository)
            ->build($loadedProduct);

        self::assertSame(29.99, $item['price']);
        self::assertSame('https://second.example/shirt', $item['product_url']);
    }

    /**
     * PRO-1458: a product that IS on the default website keeps the canonical
     * scope, even when it also sells on another website — one payload per
     * product, pinned to the canonical store (PRO-1352/1353).
     */
    public function testProductOnTheDefaultWebsiteKeepsTheCanonicalScope(): void
    {
        $storeManager = $this->canonicalStoreManager();
        $storeManager->expects(self::never())->method('getWebsite');

        $product = $this->product(42, 'SHIRT', 19.99, [1, 2]);
        $product->method('getStoreId')->willReturn(1);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->expects(self::never())->method('getById');

        $emulation = $this->createMock(Emulation::class);
        $emulation->expects(self::exactly(2)) // the product link and the image link
            ->method('startEnvironmentEmulation')
            ->with(1, Area::AREA_FRONTEND, true);

        $item = $this->createBuilder('42', $emulation, $storeManager, null, $productRepository)
            ->build($product);

        self::assertSame(19.99, $item['price']);
    }

    /**
     * PRO-1458: a product assigned to NO website keeps today's behaviour —
     * built and ingested at the canonical scope, never skipped.
     */
    public function testProductWithoutAnyWebsiteStaysOnTheCanonicalScope(): void
    {
        $storeManager = $this->canonicalStoreManager();
        $storeManager->expects(self::never())->method('getWebsite');

        $product = $this->product(42, 'SHIRT', 19.99);
        $product->method('getStoreId')->willReturn(1);

        $emulation = $this->createMock(Emulation::class);
        $emulation->expects(self::exactly(2)) // the product link and the image link
            ->method('startEnvironmentEmulation')
            ->with(1, Area::AREA_FRONTEND, true);

        $item = $this->createBuilder('42', $emulation, $storeManager)->build($product);

        self::assertSame('SHIRT', $item['sku']);
        self::assertSame(19.99, $item['price']);
    }

    /**
     * PRO-3731: the image link is read under the frontend emulation of the
     * store the product is priced and linked at, so it is the storefront
     * theme's image (or placeholder), never one of the running area's
     * (crontab under cron, adminhtml in the admin). Each emulation is stopped
     * before the next starts: Magento allows one level.
     */
    public function testImageLinkIsBuiltUnderFrontendEmulationOfTheProductsStore(): void
    {
        $websiteStore = $this->createMock(Store::class);
        $websiteStore->method('getId')->willReturn(7);
        $website = $this->createMock(Website::class);
        $website->method('getDefaultStore')->willReturn($websiteStore);
        $storeManager = $this->canonicalStoreManager();
        $storeManager->method('getWebsite')->with(2)->willReturn($website);

        $loadedProduct = $this->product(42, 'SHIRT', 19.99, [2]);
        $loadedProduct->method('getStoreId')->willReturn(1);
        $scopedProduct = $this->product(42, 'SHIRT', 29.99, [2]);
        $scopedProduct->method('getStoreId')->willReturn(7);
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($scopedProduct);

        $events = [];
        $emulation = $this->createMock(Emulation::class);
        $emulation->method('startEnvironmentEmulation')->willReturnCallback(
            static function (int $storeId, string $area, bool $force) use (&$events): void {
                $events[] = sprintf('start %d %s%s', $storeId, $area, $force ? ' forced' : '');
            }
        );
        $emulation->method('stopEnvironmentEmulation')->willReturnCallback(
            static function () use (&$events, $emulation): Emulation {
                $events[] = 'stop';

                return $emulation;
            }
        );
        $helper = $this->createMock(ImageHelper::class);
        $helper->method('init')->willReturnSelf();
        $helper->method('getUrl')->willReturnCallback(static function () use (&$events): string {
            $events[] = 'image';

            return self::IMAGE_URL;
        });
        $imageHelperFactory = $this->createMock(ImageHelperFactory::class);
        $imageHelperFactory->method('create')->willReturn($helper);

        $item = $this->createBuilder(
            '42',
            $emulation,
            $storeManager,
            null,
            $productRepository,
            imageHelperFactory: $imageHelperFactory
        )->build($loadedProduct);

        self::assertSame(self::IMAGE_URL, $item['image_url']);
        self::assertSame(
            ['start 7 frontend forced', 'stop', 'start 7 frontend forced', 'image', 'stop'],
            $events
        );
    }

    /**
     * PRO-3731: with web server rewrites off, Magento puts the running
     * script's name in each link — `magento` under bin/magento, where cron
     * builds the catalog import, the nightly re-sync and stock changes — and
     * that link does not open. The storefront's script is index.php, so it
     * takes that name's place.
     */
    public function testWithRewritesOffALinkBuiltUnderBinMagentoNamesTheStorefrontsIndexPhp(): void
    {
        $item = $this->createBuilder(
            '42',
            storeManager: $this->storeManagerWithLinkBase('http://shop.example/magento/'),
            request: $this->request('/var/www/html/bin/magento')
        )->build($this->product(42, 'SHIRT', productUrl: 'http://shop.example/magento/shirt.html?a=1'));

        self::assertSame('http://shop.example/index.php/shirt.html?a=1', $item['product_url']);
    }

    public function testWithRewritesOnALinkBuiltUnderBinMagentoIsUnchanged(): void
    {
        $item = $this->createBuilder(
            '42',
            storeManager: $this->storeManagerWithLinkBase('http://shop.example/'),
            request: $this->request('/var/www/html/bin/magento')
        )->build($this->product(42, 'SHIRT', productUrl: 'http://shop.example/magento/shirt.html'));

        self::assertSame('http://shop.example/magento/shirt.html', $item['product_url']);
    }

    public function testWithRewritesOffALinkBuiltInAWebRequestIsUnchanged(): void
    {
        $item = $this->createBuilder(
            '42',
            storeManager: $this->storeManagerWithLinkBase('http://shop.example/index.php/'),
            request: $this->request('/var/www/html/pub/index.php')
        )->build($this->product(42, 'SHIRT', productUrl: 'http://shop.example/index.php/shirt.html'));

        self::assertSame('http://shop.example/index.php/shirt.html', $item['product_url']);
    }

    /**
     * PRO-1952 (contract v1.6.0 §3): a product with no category is sent with
     * the placeholder `category_path` and `tags.category_defaulted: "true"`,
     * so the engine derives nothing from the placeholder slug. The delete
     * tombstone carries the same flag.
     */
    public function testProductWithoutACategoryIsMarkedCategoryDefaulted(): void
    {
        $builder = $this->createBuilder('42');

        $item = $builder->build($this->product(42, 'SHIRT'));
        $tombstone = $builder->buildTombstone($this->product(42, 'SHIRT'));

        self::assertSame('uncategorized', $item['category_path']);
        self::assertSame('true', $item['tags']['category_defaulted']);
        self::assertSame('true', $tombstone['tags']['category_defaulted']);
    }

    /**
     * PRO-1952: a product in a real category sends its path and no flag;
     * the contract's flag is omit-on-false.
     */
    public function testProductInARealCategorySendsNoCategoryDefaultedFlag(): void
    {
        $categories = [
            2 => $this->category(1, '1/2', 'default-category'),
            5 => $this->category(2, '1/2/5', 'shirts'),
        ];
        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepository->method('get')->willReturnCallback(static fn (int $id) => $categories[$id]);

        $item = $this->createBuilder('42', null, null, null, null, $categoryRepository)
            ->build($this->product(42, 'SHIRT', 19.99, [], 'https://shop.example/shirt', [5]));

        self::assertSame('shirts', $item['category_path']);
        self::assertArrayNotHasKey('category_defaulted', $item['tags']);
    }

    /**
     * PRO-1952: a product assigned only to the store's root category has no
     * real category either, so its placeholder is flagged too.
     */
    public function testProductOnlyInTheRootCategoryIsMarkedCategoryDefaulted(): void
    {
        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepository->method('get')->willReturn($this->category(1, '1/2', 'default-category'));

        $item = $this->createBuilder('42', null, null, null, null, $categoryRepository)
            ->build($this->product(42, 'SHIRT', 19.99, [], 'https://shop.example/shirt', [2]));

        self::assertSame('uncategorized', $item['category_path']);
        self::assertSame('true', $item['tags']['category_defaulted']);
    }

    /**
     * PRO-3714: a variant (configurable child) without a category of its own
     * is sent with its parent's category, and so is not marked defaulted.
     */
    public function testVariantWithoutACategoryOfItsOwnIsSentWithItsParentsCategory(): void
    {
        $item = $this->createBuilder('17', null, null, null, null, $this->shirtCategories(), '', null, [5])
            ->build($this->product(42, 'SHIRT-S'));

        self::assertSame('shirts', $item['category_path']);
        self::assertSame('shirts', $item['tags']['category_path']);
        self::assertArrayNotHasKey('category_defaulted', $item['tags']);
    }

    /**
     * PRO-3714: a variant with a category of its own keeps it.
     */
    public function testVariantWithACategoryOfItsOwnKeepsIt(): void
    {
        $item = $this->createBuilder('17', null, null, null, null, $this->shirtCategories(), '', null, [5])
            ->build($this->product(42, 'SHIRT-S', 19.99, [], 'https://shop.example/shirt', [6]));

        self::assertSame('shirts/linen', $item['category_path']);
        self::assertArrayNotHasKey('category_defaulted', $item['tags']);
    }

    /**
     * PRO-3714: only a variant whose parent has no category either is sent
     * with the placeholder and marked defaulted.
     */
    public function testVariantWhoseParentHasNoCategoryEitherIsMarkedCategoryDefaulted(): void
    {
        $item = $this->createBuilder('17', null, null, null, null, $this->shirtCategories())
            ->build($this->product(42, 'SHIRT-S'));

        self::assertSame('uncategorized', $item['category_path']);
        self::assertSame('true', $item['tags']['category_defaulted']);
    }

    private function shirtCategories(): CategoryRepositoryInterface&MockObject
    {
        $categories = [
            2 => $this->category(1, '1/2', 'default-category'),
            5 => $this->category(2, '1/2/5', 'shirts'),
            6 => $this->category(3, '1/2/5/6', 'linen'),
        ];
        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepository->method('get')->willReturnCallback(static fn (int $id) => $categories[$id]);

        return $categoryRepository;
    }

    private function category(int $level, string $path, string $urlKey): Category&MockObject
    {
        $category = $this->createMock(Category::class);
        $category->method('getLevel')->willReturn($level);
        $category->method('getPath')->willReturn($path);
        $category->method('getData')->willReturnCallback(
            static fn (string $key = '', $index = null) => $key === 'url_key' ? $urlKey : null
        );

        return $category;
    }

    /**
     * A store manager whose default store view is store 1 on website 1.
     */
    private function canonicalStoreManager(): StoreManagerInterface&MockObject
    {
        $canonicalStore = $this->createMock(StoreInterface::class);
        $canonicalStore->method('getId')->willReturn(1);
        $canonicalStore->method('getWebsiteId')->willReturn(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([]);
        $storeManager->method('getDefaultStoreView')->willReturn($canonicalStore);

        return $storeManager;
    }

    /**
     * The canonical store manager whose stores' link base (no store code)
     * is $linkBase, secure and not.
     */
    private function storeManagerWithLinkBase(string $linkBase): StoreManagerInterface&MockObject
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturnCallback(
            static fn (string $type = UrlInterface::URL_TYPE_LINK, $secure = null): string =>
                $type === UrlInterface::URL_TYPE_DIRECT_LINK ? $linkBase : 'http://shop.example/'
        );
        $storeManager = $this->canonicalStoreManager();
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }

    private function request(string $scriptFilename): Http&MockObject
    {
        $request = $this->createMock(Http::class);
        $request->method('getServerValue')->with('SCRIPT_FILENAME')->willReturn($scriptFilename);

        return $request;
    }

    /**
     * @param int[] $parentCategoryIds what the resolver answers for the parent's category ids
     */
    private function createBuilder(
        string $resolvedProductId,
        ?Emulation $emulation = null,
        ?StoreManagerInterface $storeManager = null,
        ?StockRegistryStorage $stockRegistryStorage = null,
        ?ProductRepositoryInterface $productRepository = null,
        ?CategoryRepositoryInterface $categoryRepository = null,
        string $storefrontUrl = '',
        ?ImageHelperFactory $imageHelperFactory = null,
        array $parentCategoryIds = [],
        ?Http $request = null
    ): CatalogPayloadBuilder {
        $parentResolver = $this->createMock(ParentProductResolver::class);
        $parentResolver->method('productIdOf')->with(42)->willReturn($resolvedProductId);
        $parentResolver->method('parentCategoryIds')->with(42)->willReturn($parentCategoryIds);

        if ($storeManager === null) {
            $storeManager = $this->createMock(StoreManagerInterface::class);
            $storeManager->method('getStores')->willReturn([]);
        }

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(true);
        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItem')->willReturn($stockItem);

        return new CatalogPayloadBuilder(
            $storeManager,
            $productRepository ?? $this->createMock(ProductRepositoryInterface::class),
            $categoryRepository ?? $this->createMock(CategoryRepositoryInterface::class),
            $stockRegistry,
            $stockRegistryStorage ?? $this->createMock(StockRegistryStorage::class),
            $imageHelperFactory ?? new ImageHelperFactory(),
            $this->createMock(LanguageResolver::class),
            $parentResolver,
            $emulation ?? $this->createMock(Emulation::class),
            $this->storefrontUrl($storefrontUrl),
            new StorefrontScript($storeManager, $request ?? $this->createMock(Http::class))
        );
    }

    private function storefrontUrl(string $saved): StorefrontUrl
    {
        $config = $this->createMock(Config::class);
        $config->method('getStorefrontUrl')->willReturn($saved);

        return new StorefrontUrl($config);
    }

    /**
     * An image helper factory whose helper answers $url for every image.
     */
    private function imageHelperFactory(string $url): ImageHelperFactory
    {
        $helper = $this->createMock(ImageHelper::class);
        $helper->method('init')->willReturnSelf();
        $helper->method('getUrl')->willReturn($url);
        $factory = $this->createMock(ImageHelperFactory::class);
        $factory->method('create')->willReturn($helper);

        return $factory;
    }

    /**
     * @param int[] $websiteIds
     * @param int[] $categoryIds
     */
    private function product(
        int $id,
        string $sku,
        float $price = 19.99,
        array $websiteIds = [],
        string $productUrl = 'https://shop.example/shirt',
        array $categoryIds = []
    ): Product&MockObject {
        $amount = $this->createMock(AmountInterface::class);
        $amount->method('getValue')->willReturn($price);
        $price = $this->createMock(PriceInterface::class);
        $price->method('getAmount')->willReturn($amount);
        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willReturn($price);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getSku')->willReturn($sku);
        $product->method('getName')->willReturn('Shirt');
        $product->method('getProductUrl')->willReturn($productUrl);
        $product->method('getPriceInfo')->willReturn($priceInfo);
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getAttributeSetId')->willReturn(4);
        $product->method('getCategoryIds')->willReturn($categoryIds);
        $product->method('getWebsiteIds')->willReturn($websiteIds);
        $product->method('getAttributeText')->willReturn(false);
        $product->method('getData')->willReturnCallback(
            static fn (string $key = '', $index = null) => ['short_description' => 'A fine shirt'][$key] ?? ''
        );

        return $product;
    }
}
