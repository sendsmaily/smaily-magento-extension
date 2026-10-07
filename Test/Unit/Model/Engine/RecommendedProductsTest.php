<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\RecommendedProducts;

/**
 * PRO-3790: the store's own products for the engine's slots, and their
 * storefront links. Values are synthetic.
 */
class RecommendedProductsTest extends TestCase
{
    private const REC_1 = '3fa85f64-5717-4562-b3fc-2c963f66afa6';
    private const REC_2 = '9b2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c';
    private const REC_3 = 'ab2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c';
    private const REC_4 = 'bb2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c';

    /** @var array<int, array{0: mixed, 1?: mixed}> */
    private array $filters = [];

    private int $collections = 0;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../Support/Stub/ProductCollectionFactory.php';
    }

    /** @var array<int, mixed> */
    private array $storeFilter = [];

    /** @var array<int, mixed> */
    private array $visibilityFilter = [];

    public function testEachSlotIsLookedUpByProductIdElseBySkuInTheSlotsOrder(): void
    {
        $items = $this->products([
            $this->product(7, 'MJ07', 'https://shop.example/jacket.html'),
            $this->product(12, '', 'https://shop.example/no-sku.html'),
            $this->product(30, 'MJ30', 'https://shop.example/tee.html'),
        ])->forSlots([
            $this->slot(self::REC_1, '', 'MJ30'),
            $this->slot(self::REC_2, '7', 'something-else'),
            $this->slot(self::REC_3, '', 'mag-12'),
        ]);

        self::assertSame(
            [
                'https://shop.example/tee.html?smaily_rec=' . self::REC_1 . '&smaily_ctx=storefront',
                'https://shop.example/jacket.html?smaily_rec=' . self::REC_2 . '&smaily_ctx=storefront',
                'https://shop.example/no-sku.html?smaily_rec=' . self::REC_3 . '&smaily_ctx=storefront',
            ],
            array_column($items, 'url')
        );
        self::assertSame([30, 7, 12], array_map(static fn (array $item): int => (int)$item['product']->getId(), $items));
        self::assertSame(
            [['attribute' => 'entity_id', 'in' => ['7', '12']], ['attribute' => 'sku', 'in' => ['MJ30']]],
            $this->filters[1][0]
        );
    }

    public function testOnlyEnabledProductsVisibleInTheCatalogOfThisStoreAreAskedFor(): void
    {
        $this->products([])->forSlots([$this->slot(self::REC_1, '7', 'MJ07')]);

        self::assertSame(['status', ['eq' => 1]], array_slice($this->filters[0], 0, 2));
        self::assertSame([3], $this->storeFilter);
        self::assertSame([[2, 4]], $this->visibilityFilter);
    }

    public function testAProductTheStoreDoesNotShowOrSellIsLeftOut(): void
    {
        $items = $this->products([
            $this->product(7, 'MJ07', 'https://shop.example/jacket.html', false),
            $this->product(8, 'MJ08', 'https://shop.example/hat.html'),
        ])->forSlots([
            $this->slot(self::REC_1, '7', 'MJ07'),
            $this->slot(self::REC_2, '99', 'GONE'),
            $this->slot(self::REC_3, '8', 'MJ08'),
        ]);

        self::assertSame(
            ['https://shop.example/hat.html?smaily_rec=' . self::REC_3 . '&smaily_ctx=storefront'],
            array_column($items, 'url')
        );
    }

    public function testALinkKeepsTheProductUrlsOwnQueryAndTheEnginesParameterNames(): void
    {
        $items = $this->products(
            [$this->product(7, 'MJ07', 'https://shop.example/catalog/product/view/id/7?___store=en')],
            ['paramRecId' => 'rec', 'paramContext' => 'ctx']
        )->forSlots([$this->slot(self::REC_4, '7', 'MJ07')]);

        self::assertSame(
            'https://shop.example/catalog/product/view/id/7?___store=en&rec=' . self::REC_4 . '&ctx=storefront',
            $items[0]['url']
        );
    }

    public function testNoSlotsAskTheCatalogNothing(): void
    {
        self::assertSame([], $this->products([])->forSlots([]));
        self::assertSame(0, $this->collections);
    }

    /**
     * @return array{rec_id: string, external_id: string, sku: string}
     */
    private function slot(string $recId, string $externalId, string $sku): array
    {
        return ['rec_id' => $recId, 'external_id' => $externalId, 'sku' => $sku];
    }

    private function product(int $id, string $sku, string $url, bool $salable = true): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getSku')->willReturn($sku);
        $product->method('getProductUrl')->willReturn($url);
        $product->method('isSalable')->willReturn($salable);

        return $product;
    }

    /**
     * @param Product[] $found what the catalog answers
     * @param array<string, string> $params the engine's landing parameter names
     */
    private function products(array $found, array $params = []): RecommendedProducts
    {
        $collection = $this->createMock(Collection::class);
        foreach (['setStoreId', 'addAttributeToSelect', 'addMinimalPrice', 'addFinalPrice', 'addTaxPercents',
                     'addUrlRewrite'] as $method) {
            $collection->method($method)->willReturnSelf();
        }
        $collection->method('addStoreFilter')->willReturnCallback(function ($store) use ($collection) {
            $this->storeFilter[] = $store;

            return $collection;
        });
        $collection->method('setVisibility')->willReturnCallback(function ($ids) use ($collection) {
            $this->visibilityFilter[] = $ids;

            return $collection;
        });
        $collection->method('addAttributeToFilter')->willReturnCallback(function (...$args) use ($collection) {
            $this->filters[] = $args;

            return $collection;
        });
        $collection->method('getIterator')->willReturn(new \ArrayIterator($found));

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($collection) {
            $this->collections++;

            return $collection;
        });

        $visibility = $this->createMock(Visibility::class);
        $visibility->method('getVisibleInCatalogIds')->willReturn([2, 4]);

        $catalogConfig = $this->createMock(CatalogConfig::class);
        $catalogConfig->method('getProductAttributes')->willReturn(['name', 'small_image']);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $attribution = $this->createMock(AttributionManager::class);
        $attribution->method('getClientConfig')->willReturn($params + [
            'paramRecId' => 'smaily_rec',
            'paramContext' => 'smaily_ctx',
        ]);

        return new RecommendedProducts($factory, $visibility, $catalogConfig, $storeManager, $attribution);
    }
}
