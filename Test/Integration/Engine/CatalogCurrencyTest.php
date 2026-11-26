<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Engine;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\ImageFactory as ImageHelperFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Pricing\Price\BasePrice;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Catalog\Pricing\Price\RegularPrice;
use Magento\Catalog\Pricing\Price\SpecialPrice;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Directory\Model\Currency;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Directory\Model\PriceCurrency;
use Magento\Directory\Model\ResourceModel\Currency as CurrencyResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ScopeInterface;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Pricing\Adjustment\CalculatorInterface;
use Magento\Framework\Pricing\Amount\Base as Amount;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\DateTime\Intl\DateFormatterFactory;
use Magento\Framework\Stdlib\DateTime\Timezone;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Smaily\Connect\Model\Backfill\EngineCatalogProcessor;
use Smaily\Connect\Model\Backfill\ImportClock;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\CatalogProductLoader;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Payload\ParentProductResolver;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\StorefrontScript;
use Smaily\Connect\Model\StorefrontUrl;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * CON-447 (contract §3 `currency`, §5 "One currency per store"): a product
 * goes to Campaign Intelligence in the store's base currency, as every
 * order does since CON-421. Magento keeps a price in the base currency and
 * its price readers convert it into the store's display currency; the row
 * carries the base amount and the base currency code. A store whose display
 * currency is its base currency sends what it sent before. The product save
 * and the catalog import build the row the same way.
 *
 * Real here: the directory_currency_rate table, Magento's currency model and
 * its rate reader, the store's current-currency and base-currency getters,
 * Magento's PriceCurrency and its catalog price classes (RegularPrice,
 * SpecialPrice, BasePrice, FinalPrice), the catalog payload builder, the
 * catalog ingest, the catalog import processor, the job table and the ingest
 * queue. The store view (base EUR) and the product are described by mocks:
 * the harness has no store or EAV tables.
 */
class CatalogCurrencyTest extends IntegrationTestCase
{
    use EngineConnectionsStub;

    /** USD per EUR. */
    private const RATE = 1.25;

    private const PRODUCT_ID = 42;

    private SchemaInstaller $schema;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Support/Stub/CurrencyFactory.php';
        require_once __DIR__ . '/../../Unit/Support/Stub/ImageFactory.php';
        $this->schema = new SchemaInstaller($this->connection);
        $this->schema->createCurrencyRate();
        $this->connection->insertMultiple('directory_currency_rate', [
            ['currency_from' => 'EUR', 'currency_to' => 'EUR', 'rate' => 1],
            ['currency_from' => 'EUR', 'currency_to' => 'USD', 'rate' => self::RATE],
        ]);
    }

    protected function tearDown(): void
    {
        $this->schema->dropCurrencyRate();
        parent::tearDown();
    }

    public function testAProductSaveSendsTheBaseCurrencyWhereTheStoreShowsAnother(): void
    {
        $store = $this->store('USD');
        $this->catalogIngest($store)->enqueueProduct($this->product($store));

        self::assertSame(
            ['price' => 40, 'currency' => 'EUR', 'compare_price' => 50],
            $this->prices($this->queuedRow())
        );
        self::assertSame('USD', $store->getCurrentCurrency()->getCode(), 'The store shows its own currency again');
    }

    public function testTheCatalogImportSendsTheRowAProductSaveSends(): void
    {
        $store = $this->store('USD');
        $this->catalogIngest($store)->enqueueProduct($this->product($store));
        $saved = $this->queuedRow();
        $this->connection->delete(IngestEventResource::TABLE_NAME);

        $jobManager = $this->objectManager->create(JobManager::class);
        $job = $jobManager->start(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Connection::PRIMARY_KEY);
        $processor = $this->importProcessor($store, $jobManager);
        for ($run = 0; $run < 5 && $job->getStatus() !== Job::STATUS_COMPLETED; $run++) {
            $processor->process($job);
        }

        self::assertSame(Job::STATUS_COMPLETED, $job->getStatus());
        $imported = $this->queuedRow();
        self::assertSame(['price' => 40, 'currency' => 'EUR', 'compare_price' => 50], $this->prices($imported));
        self::assertSame($saved, $imported);
    }

    public function testAStoreThatShowsItsBaseCurrencySendsWhatItSentBefore(): void
    {
        $store = $this->store('EUR');
        $this->catalogIngest($store)->enqueueProduct($this->product($store));

        self::assertSame(
            ['price' => 40, 'currency' => 'EUR', 'compare_price' => 50],
            $this->prices($this->queuedRow())
        );
    }

    /**
     * The price, currency and compare price of a queued row (JSON, so a
     * whole number comes back an int).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function prices(array $row): array
    {
        return array_intersect_key($row, array_flip(['price', 'currency', 'compare_price']));
    }

    /**
     * The one queued catalog row.
     *
     * @return array<string, mixed>
     */
    private function queuedRow(): array
    {
        $payloads = $this->connection->fetchCol(
            $this->connection->select()->from(IngestEventResource::TABLE_NAME, ['payload'])
                ->where('domain = ?', Client::DOMAIN_CATALOG)
        );
        self::assertCount(1, $payloads);

        return json_decode((string)$payloads[0], true);
    }

    /**
     * Store view 1 of website 1, base currency EUR, showing $display: the
     * current store of the process, as in a cron run or a product save.
     */
    private function store(string $display): Store&MockObject
    {
        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getWebsiteId', 'getBaseCurrencyCode', 'getDefaultCurrencyCode'])
            ->getMock();
        $store->method('getId')->willReturn(1);
        $store->method('getWebsiteId')->willReturn(1);
        $store->method('getBaseCurrencyCode')->willReturn('EUR');
        $store->method('getDefaultCurrencyCode')->willReturn($display);
        // Store::getBaseCurrency() and getCurrentCurrency() read these first.
        $store->setData('base_currency', $this->currency('EUR'));
        $store->setData('current_currency', $this->currency($display));

        return $store;
    }

    /**
     * Magento's currency model over the real rate table.
     */
    private function currency(string $code): Currency
    {
        $currency = (new \ReflectionClass(Currency::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AbstractModel::class, '_resource'))
            ->setValue($currency, $this->objectManager->create(CurrencyResource::class));
        $currency->setData('currency_code', $code);

        return $currency;
    }

    private function storeManager(Store $store): StoreManagerInterface
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([]);
        $storeManager->method('getDefaultStoreView')->willReturn($store);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }

    private function catalogIngest(Store $store, ?CatalogProductLoader $loader = null): CatalogIngest
    {
        return new CatalogIngest(
            $loader ?? $this->createMock(CatalogProductLoader::class),
            $this->createMock(ProductResource::class),
            $this->builder($store),
            $this->objectManager->create(IngestQueue::class),
            $this->createMock(Logger::class),
            $this->oneEngineConnection($this->settings())
        );
    }

    /**
     * The catalog import over one page holding the product.
     */
    private function importProcessor(Store $store, JobManager $jobManager): EngineCatalogProcessor
    {
        $loader = $this->createMock(CatalogProductLoader::class);
        $loader->method('count')->willReturn(1);
        $loader->method('loadPage')->willReturnCallback(
            fn (int $afterId): array => $afterId < self::PRODUCT_ID ? [$this->product($store)] : []
        );

        return new EngineCatalogProcessor(
            $jobManager,
            $this->objectManager->create(IngestQueue::class),
            $this->catalogIngest($store, $loader),
            $loader,
            new ImportClock(),
            $this->engineJobConnection($this->oneEngineConnection($this->settings()), $jobManager)
        );
    }

    private function settings(): Settings
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);

        return $settings;
    }

    private function builder(Store $store): CatalogPayloadBuilder
    {
        $storeManager = $this->storeManager($store);
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(true);
        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItem')->willReturn($stockItem);
        $parentResolver = $this->createMock(ParentProductResolver::class);
        $parentResolver->method('productIdOf')->willReturn((string)self::PRODUCT_ID);

        return new CatalogPayloadBuilder(
            $storeManager,
            $this->createMock(ProductRepositoryInterface::class),
            $this->createMock(CategoryRepositoryInterface::class),
            $stockRegistry,
            $this->createMock(StockRegistryStorage::class),
            new ImageHelperFactory(),
            $this->createMock(LanguageResolver::class),
            $parentResolver,
            $this->createMock(Emulation::class),
            new StorefrontUrl($this->createMock(Config::class)),
            new StorefrontScript($storeManager, $this->createMock(Http::class))
        );
    }

    /**
     * A product at store view 1 whose price is 50.00 EUR, on sale for
     * 40.00 EUR until ten days from now, priced by Magento's catalog price
     * classes through Magento's PriceCurrency on the store.
     */
    private function product(Store $store): Product&MockObject
    {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getId', 'getSku', 'getPrice', 'getPriceInfo', 'getWebsiteIds', 'getCategoryIds',
                'getProductUrl', 'getAttributeText',
            ])
            ->getMock();
        $product->setData([
            'entity_id' => self::PRODUCT_ID, 'type_id' => 'simple', 'sku' => 'SHIRT', 'name' => 'Shirt',
            'status' => 1, 'visibility' => 4, 'store_id' => 1, 'price' => 50,
            'special_price' => 40,
            'special_from_date' => gmdate('Y-m-d 00:00:00', time() - 5 * 86400),
            'special_to_date' => gmdate('Y-m-d 00:00:00', time() + 10 * 86400),
        ]);
        $product->method('getId')->willReturn(self::PRODUCT_ID);
        $product->method('getSku')->willReturn('SHIRT');
        $product->method('getPrice')->willReturnCallback(static fn () => $product->getData('price'));
        $product->method('getWebsiteIds')->willReturn([1]);
        $product->method('getCategoryIds')->willReturn([]);
        $product->method('getProductUrl')->willReturn('https://shop.example/shirt.html');
        $product->method('getAttributeText')->willReturn(false);
        $product->method('getPriceInfo')->willReturn($this->priceInfo($product, $store));

        return $product;
    }

    private function priceInfo(Product $product, Store $store): PriceInfoInterface
    {
        $calculator = $this->createMock(CalculatorInterface::class);
        $calculator->method('getAmount')->willReturnCallback(static fn ($amount): Amount => new Amount($amount));
        $priceCurrency = new PriceCurrency(
            $this->storeManager($store),
            new CurrencyFactory(),
            $this->createMock(LoggerInterface::class)
        );

        $scopeResolver = $this->createMock(ScopeResolverInterface::class);
        $scopeResolver->method('getScope')->willReturn($this->createMock(ScopeInterface::class));
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('UTC');
        $timezone = new Timezone(
            $scopeResolver,
            $this->createMock(ResolverInterface::class),
            new DateTime(),
            $scopeConfig,
            'store',
            'general/locale/timezone',
            $this->createMock(DateFormatterFactory::class)
        );

        $factories = [
            RegularPrice::PRICE_CODE => static fn (): PriceInterface
                => new RegularPrice($product, 1, $calculator, $priceCurrency),
            SpecialPrice::PRICE_CODE => static fn (): PriceInterface
                => new SpecialPrice($product, 1, $calculator, $priceCurrency, $timezone),
            BasePrice::PRICE_CODE => static fn (): PriceInterface
                => new BasePrice($product, 1, $calculator, $priceCurrency),
            FinalPrice::PRICE_CODE => static fn (): PriceInterface
                => new FinalPrice($product, 1, $calculator, $priceCurrency),
        ];

        return new class ($factories) implements PriceInfoInterface {
            /** @var array<string, PriceInterface> */
            private array $prices = [];

            /**
             * @param array<string, callable(): PriceInterface> $factories
             */
            public function __construct(private readonly array $factories)
            {
            }

            /**
             * @return PriceInterface[]
             */
            public function getPrices(): array
            {
                return [$this->getPrice(RegularPrice::PRICE_CODE), $this->getPrice(SpecialPrice::PRICE_CODE)];
            }

            /**
             * @param string $priceCode
             */
            public function getPrice($priceCode): PriceInterface
            {
                return $this->prices[$priceCode] ??= ($this->factories[$priceCode])();
            }

            /**
             * @return array<string, mixed>
             */
            public function getAdjustments(): array
            {
                return [];
            }

            /**
             * @param string $adjustmentCode
             */
            public function getAdjustment($adjustmentCode): never
            {
                throw new \LogicException('No adjustments in this test');
            }
        };
    }
}
