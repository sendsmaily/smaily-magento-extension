<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Rss;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Helper\ImageFactory as ImageHelperFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Rss\FeedBuilder;
use Smaily\Connect\Model\StorefrontUrl;

/**
 * PRO-3660: a store with a separate storefront lists each item's link and
 * guid on the storefront's address, path and query string unchanged; the
 * image enclosure keeps Magento's media link.
 */
class FeedBuilderTest extends TestCase
{
    private const IMAGE_URL = 'https://backend.example.com/media/catalog/product/o/a/oak.jpg';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../Support/Stub/ImageFactory.php';
        require_once __DIR__ . '/../../Support/Stub/ProductCollectionFactory.php';
    }

    public function testItemLinksStartWithTheStorefrontUrl(): void
    {
        $item = $this->firstItem($this->build('https://shop.example.com/'));

        self::assertSame('https://shop.example.com/oak-table.html?a=1', (string)$item->link);
        self::assertSame('https://shop.example.com/oak-table.html?a=1', (string)$item->guid);
        self::assertSame(self::IMAGE_URL, (string)$item->enclosure['url']);
    }

    public function testWithoutAStorefrontUrlItemLinksAreMagentosOwn(): void
    {
        $item = $this->firstItem($this->build(''));

        self::assertSame('https://backend.example.com/oak-table.html?a=1', (string)$item->link);
        self::assertSame('https://backend.example.com/oak-table.html?a=1', (string)$item->guid);
        self::assertSame(self::IMAGE_URL, (string)$item->enclosure['url']);
    }

    private function firstItem(string $xml): \SimpleXMLElement
    {
        $rss = simplexml_load_string($xml);
        self::assertInstanceOf(\SimpleXMLElement::class, $rss);

        return $rss->channel->item[0];
    }

    private function build(string $storefrontUrl): string
    {
        $config = $this->createMock(Config::class);
        $config->method('getStorefrontUrl')->with(3)->willReturn($storefrontUrl);

        $amount = $this->createMock(AmountInterface::class);
        $amount->method('getValue')->willReturn(10.0);
        $price = $this->createMock(PriceInterface::class);
        $price->method('getAmount')->willReturn($amount);
        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willReturn($price);

        $product = $this->createMock(Product::class);
        $product->method('getName')->willReturn('Oak table');
        $product->method('getProductUrl')->willReturn('https://backend.example.com/oak-table.html?a=1');
        $product->method('getCreatedAt')->willReturn('2026-10-01 10:00:00');
        $product->method('getData')->willReturn('');
        $product->method('getPriceInfo')->willReturn($priceInfo);

        $collection = $this->createMock(Collection::class);
        foreach ([
            'setStoreId', 'addAttributeToSelect', 'addAttributeToFilter', 'setVisibility',
            'addStoreFilter', 'addUrlRewrite', 'addPriceData', 'setOrder', 'setPageSize',
        ] as $method) {
            $collection->method($method)->willReturnSelf();
        }
        $collection->method('getItems')->willReturn([$product]);
        $collectionFactory = $this->createMock(ProductCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $imageHelper = $this->createMock(ImageHelper::class);
        $imageHelper->method('init')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn(self::IMAGE_URL);
        $imageFactory = $this->createMock(ImageHelperFactory::class);
        $imageFactory->method('create')->willReturn($imageHelper);

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(3);
        $store->method('getName')->willReturn('Default Store View');
        $store->method('getBaseUrl')->willReturn('https://backend.example.com/');

        $builder = new FeedBuilder(
            $collectionFactory,
            $this->createMock(CategoryRepositoryInterface::class),
            $this->createMock(Visibility::class),
            $imageFactory,
            $this->createMock(Logger::class),
            new StorefrontUrl($config)
        );

        return $builder->build($store, null, 50, 'created_at', 'desc');
    }
}
