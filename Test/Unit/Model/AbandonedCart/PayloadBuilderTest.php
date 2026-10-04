<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\AbandonedCart;

use Magento\Catalog\Helper\ImageFactory as ImageHelperFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\AbandonedCart\PayloadBuilder;
use Smaily\Connect\Model\AbandonedCart\RestoreTokenManager;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\StorefrontScript;

/**
 * PRO-1275: the reminder recipient falls back from quote.customer_email (set
 * only once payment info is entered) to the billing then shipping address
 * email, so guests who abandon at/before the shipping step are still reached.
 *
 * PRO-1760: product details are always sent (no merchant selection), and all
 * ten slots are written on every send so a smaller cart clears the previous
 * one from the contact.
 */
class PayloadBuilderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../Support/Stub/ImageFactory.php';
        require_once __DIR__ . '/../../Support/Stub/ProductCollectionFactory.php';
    }

    public function testCustomerEmailIsUsedWhenPresent(): void
    {
        $quote = $this->quote(' Mari@Example.com ', 'billing@example.com', 'shipping@example.com');

        self::assertSame('mari@example.com', $this->build($quote)['email']);
    }

    public function testFallsBackToBillingAddressEmailWhenCustomerEmailIsNull(): void
    {
        $quote = $this->quote(null, ' Billing@Example.com ', 'shipping@example.com');

        self::assertSame('billing@example.com', $this->build($quote)['email']);
    }

    public function testFallsBackToShippingAddressEmailWhenNoBillingEmail(): void
    {
        $quote = $this->quote('', '', ' Shipping@Example.com ');

        self::assertSame('shipping@example.com', $this->build($quote)['email']);
    }

    public function testEmailIsEmptyWhenNoSourceCarriesOne(): void
    {
        $quote = $this->quote(null, '', null);

        self::assertSame('', $this->build($quote)['email']);
    }

    public function testEveryProductSlotIsWrittenEvenForAnEmptyCart(): void
    {
        $address = $this->build($this->quote('mari@example.com', null, null));

        foreach (['name', 'description', 'image_url', 'sku', 'quantity', 'price', 'base_price'] as $field) {
            for ($slot = 1; $slot <= 10; $slot++) {
                self::assertArrayHasKey(sprintf('product_%s_%d', $field, $slot), $address);
                self::assertSame('', $address[sprintf('product_%s_%d', $field, $slot)]);
            }
        }
    }

    /** Why the unused slots are sent empty rather than omitted: PayloadBuilder's class docblock. */
    public function testUnusedSlotsAreSentEmptyAlongsideTheCartsOwnProducts(): void
    {
        $quote = $this->quote('mari@example.com', null, null, [
            $this->item('Tent', 'TENT-1', 2.0, 149.0),
            $this->item('Mug', 'MUG-1', 1.0, 9.5),
        ]);

        $address = $this->build($quote);

        self::assertSame('Tent', $address['product_name_1']);
        self::assertSame('TENT-1', $address['product_sku_1']);
        self::assertSame('2', $address['product_quantity_1']);
        self::assertSame('149.00', $address['product_price_1']);
        self::assertSame('Mug', $address['product_name_2']);
        self::assertSame('', $address['product_name_3']);
        self::assertSame('', $address['product_sku_10']);
        // Nothing resolved the product, so those fields keep their prefill.
        self::assertSame('', $address['product_description_1']);
        self::assertArrayNotHasKey('over_10_products', $address);
    }

    /**
     * PRO-3732: with web server rewrites off, Magento puts the running
     * script's name in each link — `magento` under bin/magento, where cron
     * builds the reminder — and that link does not open. The cart link and
     * the store link name the storefront's index.php instead.
     */
    public function testWithRewritesOffTheCartAndStoreLinksBuiltUnderBinMagentoNameTheStorefrontsIndexPhp(): void
    {
        $address = $this->build(
            $this->quote('mari@example.com', null, null),
            $this->storeManagerWithLinkBase('http://shop.example/magento/'),
            $this->request('/var/www/html/bin/magento')
        );

        self::assertSame(
            'http://shop.example/index.php/smaily/cart/restore/id/12/ts/1/token/abc/',
            $address['abandoned_cart_url']
        );
        self::assertSame('http://shop.example/index.php/', $address['store_url']);
    }

    public function testWithRewritesOnTheCartAndStoreLinksBuiltUnderBinMagentoAreUnchanged(): void
    {
        $address = $this->build(
            $this->quote('mari@example.com', null, null),
            $this->storeManagerWithLinkBase('http://shop.example/'),
            $this->request('/var/www/html/bin/magento')
        );

        self::assertSame('http://shop.example/smaily/cart/restore/id/12/ts/1/token/abc/', $address['abandoned_cart_url']);
        self::assertSame('http://shop.example/', $address['store_url']);
    }

    /**
     * @return array<string, string>
     */
    private function build(Quote $quote, ?StoreManagerInterface $storeManager = null, ?Http $request = null): array
    {
        if ($storeManager === null) {
            $storeManager = $this->createMock(StoreManagerInterface::class);
            // Store context is decorative; a failure keeps the address valid.
            $storeManager->method('getStore')->willThrowException(new LocalizedException(__('no store')));
        }

        $builder = new PayloadBuilder(
            $storeManager,
            $this->productCollectionFactory(),
            new ImageHelperFactory(),
            $this->createMock(RestoreTokenManager::class),
            $this->createMock(Logger::class),
            new StorefrontScript($storeManager, $request ?? $this->createMock(Http::class))
        );

        return $builder->build($quote);
    }

    /**
     * A store whose links start at `$linkBase`, as Magento builds them: with
     * rewrites off, the base ends in the running script's name.
     */
    private function storeManagerWithLinkBase(string $linkBase): StoreManagerInterface
    {
        $store = $this->createMock(Store::class);
        $store->method('getName')->willReturn('Shop');
        $store->method('getWebsite')->willReturn($this->createMock(Website::class));
        $store->method('getBaseUrl')->willReturnCallback(
            static fn (string $type = UrlInterface::URL_TYPE_LINK): string => $linkBase
        );
        $store->method('getUrl')->with('smaily/cart/restore')
            ->willReturn($linkBase . 'smaily/cart/restore/id/12/ts/1/token/abc/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->with(1)->willReturn($store);

        return $storeManager;
    }

    private function request(string $scriptFilename): Http
    {
        $request = $this->createMock(Http::class);
        $request->method('getServerValue')->with('SCRIPT_FILENAME')->willReturn($scriptFilename);

        return $request;
    }

    /**
     * A collection that finds no product: the item-level fields still resolve,
     * the product-level ones keep their empty prefill.
     */
    private function productCollectionFactory(): ProductCollectionFactory
    {
        $collection = $this->createMock(ProductCollection::class);
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('addPriceData')->willReturnSelf();
        $collection->method('getItems')->willReturn([]);

        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }

    /**
     * @param Item[] $items
     */
    private function quote(
        ?string $customerEmail,
        ?string $billingEmail,
        ?string $shippingEmail,
        array $items = []
    ): Quote {
        // getCustomerEmail / *name are magic getters (addMethods); getStoreId,
        // getAllVisibleItems and the address getters are real methods.
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCustomerEmail', 'getCustomerFirstname', 'getCustomerLastname'])
            ->onlyMethods(['getStoreId', 'getBillingAddress', 'getShippingAddress', 'getAllVisibleItems'])
            ->getMock();
        $quote->method('getCustomerEmail')->willReturn($customerEmail);
        $quote->method('getCustomerFirstname')->willReturn(null);
        $quote->method('getCustomerLastname')->willReturn(null);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getBillingAddress')->willReturn($this->address($billingEmail));
        $quote->method('getShippingAddress')->willReturn($this->address($shippingEmail));
        $quote->method('getAllVisibleItems')->willReturn($items);

        return $quote;
    }

    private function item(string $name, string $sku, float $qty, float $price): Item
    {
        // getPriceInclTax is a magic data getter; the rest are real methods.
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->addMethods(['getPriceInclTax'])
            ->onlyMethods(['getName', 'getSku', 'getQty', 'getPrice', 'getProduct'])
            ->getMock();
        $item->method('getName')->willReturn($name);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQty')->willReturn($qty);
        $item->method('getPrice')->willReturn($price);
        $item->method('getPriceInclTax')->willReturn($price);
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(7);
        $item->method('getProduct')->willReturn($product);

        return $item;
    }

    private function address(?string $email): ?Address
    {
        if ($email === null) {
            return null;
        }
        $address = $this->createMock(Address::class);
        $address->method('getEmail')->willReturn($email);

        return $address;
    }
}
