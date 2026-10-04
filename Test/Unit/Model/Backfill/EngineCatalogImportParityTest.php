<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Backfill;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\ImageFactory as ImageHelperFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Pricing\Price\BasePrice;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Catalog\Pricing\Price\RegularPrice;
use Magento\Catalog\Pricing\Price\SpecialPrice;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ScopeInterface;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Pricing\Adjustment\CalculatorInterface;
use Magento\Framework\Pricing\Amount\Base as Amount;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\Pricing\SaleableInterface;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\DateTime\Intl\DateFormatterFactory;
use Magento\Framework\Stdlib\DateTime\Timezone;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\EngineCatalogProcessor;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\CatalogProductLoader;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Payload\ParentProductResolver;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\StorefrontScript;
use Smaily\Connect\Model\StorefrontUrl;

/**
 * PRO-3692: a product sent by the catalog import carries the same price,
 * compare price and sale end date as the same product sent on save.
 *
 * The save path hands CatalogIngest the saved product, which holds every
 * attribute. The import hands it a collection item, which holds only the
 * static columns plus what CatalogProductLoader selects. Both run through
 * the real CatalogIngest and CatalogPayloadBuilder, priced by Magento's own
 * RegularPrice, SpecialPrice, BasePrice and FinalPrice (the sale window is
 * Magento's Timezone::isScopeDateInInterval()). The adjustment calculator
 * stands in for Magento's bundle Calculator: it adds a bundle's selections
 * as a fixed or a dynamic bundle by `price_type`, as that Calculator does.
 *
 * PRO-1967: a stock change is built the same way since — its product comes
 * from the same CatalogProductLoader collection — so it too sends the row a
 * product save sends.
 */
class EngineCatalogImportParityTest extends TestCase
{
    private const CANONICAL_STORE_ID = 1;

    /** A fixed bundle's own selection prices, added to its price. */
    private const FIXED_SELECTIONS = 5.0;

    /** A dynamic bundle's children's prices, added to its price. */
    private const DYNAMIC_SELECTIONS = 30.0;

    /** Columns of catalog_product_entity: every collection item has them. */
    private const STATIC_COLUMNS = ['entity_id', 'attribute_set_id', 'type_id', 'sku', 'has_options'];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../Support/Stub/ProductCollectionFactory.php';
        require_once __DIR__ . '/../../Support/Stub/ImageFactory.php';
    }

    /**
     * @return array<string, array{array<string, mixed>, float, ?float, bool}>
     */
    public static function products(): array
    {
        $day = static fn (int $days): string => gmdate('Y-m-d 00:00:00', time() + $days * 86400);

        return [
            'a sale that runs for ten more days' => [
                ['special_price' => 40, 'special_from_date' => $day(-5), 'special_to_date' => $day(10)],
                40.0, 50.0, true,
            ],
            'a sale that ended' => [
                ['special_price' => 40, 'special_from_date' => $day(-20), 'special_to_date' => $day(-10)],
                50.0, null, false,
            ],
            'a sale that has not started' => [
                ['special_price' => 40, 'special_from_date' => $day(5), 'special_to_date' => $day(15)],
                50.0, null, false,
            ],
            'a fixed-price bundle' => [
                ['type_id' => 'bundle', 'price_type' => 1, 'price' => 100],
                105.0, null, false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('products')]
    public function testTheImportSendsTheSamePriceAndSaleEndAsAProductSave(
        array $row,
        float $price,
        ?float $comparePrice,
        bool $onSale
    ): void {
        $row += [
            'entity_id' => 42, 'attribute_set_id' => 4, 'type_id' => 'simple', 'sku' => 'SHIRT',
            'has_options' => 0, 'name' => 'Shirt', 'status' => 1, 'visibility' => 4, 'price' => 50,
            'tax_class_id' => 2, 'url_key' => 'shirt',
        ];

        $saved = $this->sendOnSave($this->product($row));
        $imported = $this->sendByImport($row);
        $stockChange = $this->sendOnStockChange($row);

        $fields = static fn (array $item): array => array_intersect_key(
            $item,
            array_flip(['price', 'compare_price', 'on_sale_until'])
        );
        self::assertSame($fields($saved), $fields($imported));
        self::assertSame($saved, $stockChange);
        self::assertSame($price, $saved['price']);
        self::assertSame($comparePrice, $saved['compare_price'] ?? null);
        self::assertSame($onSale, isset($saved['on_sale_until']));
    }

    /**
     * @param Product $product
     * @return array<string, mixed>
     */
    private function sendOnSave(Product $product): array
    {
        $sent = [];
        $this->catalogIngest($sent)->enqueueProduct($product);
        self::assertCount(1, $sent);

        return $sent[0];
    }

    /**
     * Runs one import page; the collection item holds only the static
     * columns plus the attributes CatalogProductLoader selects.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function sendByImport(array $row): array
    {
        $collectionFactory = $this->collectionFactory($row);

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->method('isCancelled')->willReturn(true);
        $job = $this->createMock(Job::class);
        $job->method('getCursorValue')->willReturn('0');

        $sent = [];
        $ingestQueue = $this->createMock(IngestQueue::class);
        $ingestQueue->method('countPending')->willReturn(0);
        $payloadBuilder = $this->payloadBuilder();
        (new EngineCatalogProcessor(
            $jobManager,
            $collectionFactory,
            $payloadBuilder,
            $ingestQueue,
            $this->catalogIngest($sent),
            new CatalogProductLoader($collectionFactory, $payloadBuilder)
        ))->process($job);
        self::assertCount(1, $sent);

        return $sent[0];
    }

    /**
     * Records a stock change and runs the flusher's build; the collection
     * item holds only the static columns plus the attributes it selects.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function sendOnStockChange(array $row): array
    {
        $marker = $this->createMock(IngestEvent::class);
        $marker->method('getEntityId')->willReturn('42');
        $sent = [];
        $ingestQueue = $this->createMock(IngestQueue::class);
        $ingestQueue->method('claimBatch')->willReturn([$marker]);
        $ingestQueue->method('enqueueChangedPayloads')->willReturnCallback(
            function (string $domain, array $payloads) use (&$sent): int {
                foreach ($payloads as $payload) {
                    $sent[] = $payload;
                }

                return count($payloads);
            }
        );

        $this->catalogIngest($sent, $this->collectionFactory($row), $ingestQueue)->buildChanged();
        self::assertCount(1, $sent);

        return $sent[0];
    }

    /**
     * A product collection whose one item holds only the static columns plus
     * the attributes the caller selects.
     *
     * @param array<string, mixed> $row
     */
    private function collectionFactory(array $row): ProductCollectionFactory
    {
        $selected = [];
        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToSelect')->willReturnCallback(
            function (array $attributes) use (&$selected, $collection): Collection {
                $selected = $attributes;

                return $collection;
            }
        );
        $collection->method('getItems')->willReturnCallback(
            function () use (&$selected, $row): array {
                return [$this->product(array_intersect_key(
                    $row,
                    array_flip([...self::STATIC_COLUMNS, ...$selected])
                ))];
            }
        );
        $collection->method('getSize')->willReturn(1);
        $collectionFactory = $this->createMock(ProductCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        return $collectionFactory;
    }

    /**
     * @param array<int, array<string, mixed>> $sent
     */
    private function catalogIngest(
        array &$sent,
        ?ProductCollectionFactory $collectionFactory = null,
        (IngestQueue&MockObject)|null $ingestQueue = null
    ): CatalogIngest {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);
        $ingestQueue ??= $this->createMock(IngestQueue::class);
        $ingestQueue->method('enqueue')->willReturnCallback(
            function (string $domain, array $item) use (&$sent): bool {
                $sent[] = $item;

                return true;
            }
        );

        $payloadBuilder = $this->payloadBuilder();

        return new CatalogIngest(
            $settings,
            new CatalogProductLoader(
                $collectionFactory ?? $this->createMock(ProductCollectionFactory::class),
                $payloadBuilder
            ),
            $this->createMock(ProductResource::class),
            $payloadBuilder,
            $ingestQueue,
            $this->createMock(Logger::class)
        );
    }

    private function payloadBuilder(): CatalogPayloadBuilder
    {
        $canonicalStore = $this->createMock(StoreInterface::class);
        $canonicalStore->method('getId')->willReturn(self::CANONICAL_STORE_ID);
        $canonicalStore->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([]);
        $storeManager->method('getDefaultStoreView')->willReturn($canonicalStore);

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(true);
        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItem')->willReturn($stockItem);

        $parentResolver = $this->createMock(ParentProductResolver::class);
        $parentResolver->method('productIdOf')->willReturn('42');

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
     * A product holding exactly $data, loaded at the canonical store and
     * priced by Magento's catalog price classes.
     *
     * @param array<string, mixed> $data
     */
    private function product(array $data): Product&MockObject
    {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getId', 'getSku', 'getPrice', 'getPriceInfo', 'getWebsiteIds', 'getCategoryIds',
                'getProductUrl', 'getAttributeText',
            ])
            ->getMock();
        $product->setData($data + ['store_id' => self::CANONICAL_STORE_ID]);
        $product->method('getId')->willReturn($data['entity_id']);
        $product->method('getSku')->willReturn($data['sku']);
        // Magento's Type\Price::getPrice() returns the `price` data.
        $product->method('getPrice')->willReturnCallback(static fn () => $product->getData('price'));
        $product->method('getWebsiteIds')->willReturn([1]);
        $product->method('getCategoryIds')->willReturn([]);
        $product->method('getProductUrl')->willReturn('https://shop.example/shirt.html');
        $product->method('getAttributeText')->willReturn(false);
        $product->method('getPriceInfo')->willReturn($this->priceInfo($product));

        return $product;
    }

    private function priceInfo(Product $product): PriceInfoInterface
    {
        $calculator = $this->createMock(CalculatorInterface::class);
        $calculator->method('getAmount')->willReturnCallback(
            static function ($amount, SaleableInterface $item): Amount {
                if ($item instanceof Product && $item->getTypeId() === 'bundle') {
                    $amount += (int)$item->getData('price_type') === 1
                        ? self::FIXED_SELECTIONS
                        : self::DYNAMIC_SELECTIONS;
                }

                return new Amount($amount);
            }
        );
        $currency = $this->createMock(PriceCurrencyInterface::class);
        $currency->method('convertAndRound')->willReturnCallback(
            static fn ($amount): float => round((float)$amount, 2)
        );

        $scope = $this->createMock(ScopeInterface::class);
        $scopeResolver = $this->createMock(ScopeResolverInterface::class);
        $scopeResolver->method('getScope')->willReturn($scope);
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
                => new RegularPrice($product, 1, $calculator, $currency),
            SpecialPrice::PRICE_CODE => static fn (): PriceInterface
                => new SpecialPrice($product, 1, $calculator, $currency, $timezone),
            BasePrice::PRICE_CODE => static fn (): PriceInterface
                => new BasePrice($product, 1, $calculator, $currency),
            FinalPrice::PRICE_CODE => static fn (): PriceInterface
                => new FinalPrice($product, 1, $calculator, $currency),
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
