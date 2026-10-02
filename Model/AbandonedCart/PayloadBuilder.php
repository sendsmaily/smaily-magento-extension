<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\AbandonedCart;

use Magento\Catalog\Helper\ImageFactory as ImageHelperFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Builds the abandoned cart automation address payload.
 *
 * Field names are the cross-platform contract shared with the WooCommerce
 * and Shopify plugins: numbered slots product_name_1..10, product_sku_N,
 * product_quantity_N, product_price_N (incl. tax), product_base_price_N,
 * product_description_N, product_image_url_N, plus over_10_products,
 * is_abandoned_cart and abandoned_cart_url.
 *
 * Product details are NOT selectable: every product field rides every
 * reminder, and every one of the ten slots is written on every send (unused
 * ones empty). Smaily leaves an absent field intact and overwrites an empty
 * one, so writing the full matrix is what clears the previous, larger cart
 * from the contact. Templates decide what to render.
 */
class PayloadBuilder
{
    private const MAX_PRODUCTS = 10;

    /** @var array<string, string> The ten empty slots, keyed off productRow(). */
    private static array $blankSlots = [];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ImageHelperFactory $imageHelperFactory,
        private readonly RestoreTokenManager $restoreTokenManager,
        private readonly Logger $logger
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function build(Quote $quote): array
    {
        $address = [
            'email' => $this->resolveEmail($quote),
            'is_abandoned_cart' => 'true',
        ];

        $firstname = trim((string)$quote->getCustomerFirstname());
        $lastname = trim((string)$quote->getCustomerLastname());
        if ($firstname !== '') {
            $address['first_name'] = $firstname;
        }
        if ($lastname !== '') {
            $address['last_name'] = $lastname;
        }

        try {
            $store = $this->storeManager->getStore((int)$quote->getStoreId());
            if ($store instanceof \Magento\Store\Model\Store) {
                // Legacy Magento templates use {{store}} as the store NAME —
                // kept for upgrade continuity; store_url serves templates
                // shared with the Woo/Shopify plugins (which send a URL).
                $address['store'] = (string)$store->getName();
                $address['store_url'] = (string)$store->getBaseUrl();
                // getGroup(), not the magic getStoreGroup() (silently null).
                $group = $store->getGroup();
                $address['store_group'] = $group ? (string)$group->getName() : '';
                $address['store_website'] = (string)$store->getWebsite()->getName();
                // A signed recovery link that restores this exact quote
                // for 30 days from now, when the reminder is created.
                $address['abandoned_cart_url'] = $store->getUrl(
                    'smaily/cart/restore',
                    $this->restoreTokenManager->linkParams((int)$quote->getId())
                );
            }
        } catch (LocalizedException) {
            // Store context is decorative; the address stays valid without it.
        }

        return array_merge($address, $this->productFields($quote));
    }

    /**
     * Resolves the recipient email with the same fallback order as the cron's
     * quote selection: quote.customer_email (set once payment info is entered),
     * then the billing address email, then the shipping address email. Guests
     * who abandon at/before the shipping step have an empty customer_email and
     * carry their email only on the quote address (PRO-1275).
     *
     * @param Quote $quote
     */
    private function resolveEmail(Quote $quote): string
    {
        $email = trim((string)$quote->getCustomerEmail());

        if ($email === '') {
            $billing = $quote->getBillingAddress();
            $email = $billing ? trim((string)$billing->getEmail()) : '';
        }

        if ($email === '') {
            $shipping = $quote->getShippingAddress();
            $email = $shipping ? trim((string)$shipping->getEmail()) : '';
        }

        return strtolower($email);
    }

    /**
     * @return array<string, string>
     */
    private function productFields(Quote $quote): array
    {
        $fields = $this->blankSlots();

        $items = $quote->getAllVisibleItems();
        $products = $this->loadProducts($items, (int)$quote->getStoreId());

        $slot = 0;
        foreach ($items as $item) {
            if ($slot >= self::MAX_PRODUCTS) {
                $fields['over_10_products'] = 'true';
                break;
            }
            $slot++;
            $product = $products[(int)$item->getProduct()->getId()] ?? null;

            foreach ($this->productRow($item, $product) as $field => $value) {
                $fields[sprintf('product_%s_%d', $field, $slot)] = $value;
            }
        }

        return $fields;
    }

    /**
     * The product half of one slot: payload key suffix => value. A null item
     * yields the blank row every unused slot is prefilled with.
     *
     * @return array<string, string>
     */
    private function productRow(?Item $item, ?Product $product): array
    {
        $description = $product === null ? '' : trim(strip_tags(
            (string)($product->getData('short_description') ?: $product->getData('description'))
        ));
        $regular = $product === null
            ? 0.0
            : (float)$product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();

        return [
            'name' => (string)$item?->getName(),
            'description' => $description,
            'image_url' => $product === null ? '' : (string)$this->imageUrl($product),
            'sku' => (string)$item?->getSku(),
            'quantity' => $item === null ? '' : (string)(float)$item->getQty(),
            'price' => $item === null
                ? ''
                : number_format((float)($item->getPriceInclTax() ?: $item->getPrice()), 2, '.', ''),
            'base_price' => $regular > 0 ? number_format($regular, 2, '.', '') : '',
        ];
    }

    /**
     * Every slot of every product key, empty — see the class docblock for why
     * a send writes them all. Same for every cart, so built once per class.
     *
     * @return array<string, string>
     */
    private function blankSlots(): array
    {
        if (self::$blankSlots === []) {
            foreach (array_keys($this->productRow(null, null)) as $field) {
                for ($i = 1; $i <= self::MAX_PRODUCTS; $i++) {
                    self::$blankSlots[sprintf('product_%s_%d', $field, $i)] = '';
                }
            }
        }

        return self::$blankSlots;
    }

    /**
     * @param Item[] $items
     * @return array<int, Product>
     */
    private function loadProducts(array $items, int $storeId): array
    {
        $productIds = [];
        foreach ($items as $item) {
            $productIds[] = (int)$item->getProduct()->getId();
        }
        if (!$productIds) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'short_description', 'description', 'thumbnail', 'image', 'price'])
            ->addIdFilter($productIds)
            ->addPriceData();

        $products = [];
        foreach ($collection->getItems() as $product) {
            if ($product instanceof Product) {
                $products[(int)$product->getId()] = $product;
            }
        }

        return $products;
    }

    private function imageUrl(Product $product): ?string
    {
        try {
            $url = $this->imageHelperFactory->create()
                ->init($product, 'product_page_image_small')
                ->resize(346)
                ->getUrl();

            return $url !== '' ? $url : null;
        } catch (\Throwable $exception) {
            $this->logger->debug('Abandoned cart image URL failed', [
                'product_id' => $product->getId(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
