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
use Smaily\Connect\Model\StorefrontScript;

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
        private readonly Logger $logger,
        private readonly StorefrontScript $storefrontScript
    ) {
    }

    /**
     * The reminder address of each cart, by quote id. Built under frontend
     * emulation of the carts' store (the cron emulates once per store): the
     * carts' products load in one collection per store, and each product's
     * description, image link and regular price resolve once per store, not
     * once per cart line (PRO-1960).
     *
     * @param Quote[] $quotes
     * @return array<int, array<string, string>>
     */
    public function buildAll(array $quotes): array
    {
        $items = [];
        $productIds = [];
        foreach ($quotes as $key => $quote) {
            $items[$key] = $quote->getAllVisibleItems();
            foreach ($items[$key] as $item) {
                $productIds[(int)$quote->getStoreId()][] = (int)$item->getProduct()->getId();
            }
        }
        $products = [];
        foreach ($productIds as $storeId => $ids) {
            $products[$storeId] = $this->loadProducts($ids, $storeId);
        }

        $details = [];
        $addresses = [];
        foreach ($quotes as $key => $quote) {
            $storeId = (int)$quote->getStoreId();
            $details[$storeId] ??= [];
            $addresses[(int)$quote->getId()] = array_merge(
                $this->contactFields($quote),
                $this->productFields($items[$key], $products[$storeId] ?? [], $details[$storeId])
            );
        }

        return $addresses;
    }

    /**
     * @return array<string, string>
     */
    private function contactFields(Quote $quote): array
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
            $storeId = (int)$quote->getStoreId();
            $store = $this->storeManager->getStore($storeId);
            if ($store instanceof \Magento\Store\Model\Store) {
                // Legacy Magento templates use {{store}} as the store NAME —
                // kept for upgrade continuity; store_url serves templates
                // shared with the Woo/Shopify plugins (which send a URL).
                // Cron builds the reminder, so both links name the
                // storefront's script, not bin/magento's (PRO-3732).
                $address['store'] = (string)$store->getName();
                $address['store_url'] = $this->storefrontScript->apply((string)$store->getBaseUrl(), $storeId);
                // getGroup(), not the magic getStoreGroup() (silently null).
                $group = $store->getGroup();
                $address['store_group'] = $group ? (string)$group->getName() : '';
                $address['store_website'] = (string)$store->getWebsite()->getName();
                // A signed recovery link that restores this exact quote
                // for 30 days from now, when the reminder is created.
                $address['abandoned_cart_url'] = $this->storefrontScript->apply(
                    $store->getUrl(
                        'smaily/cart/restore',
                        $this->restoreTokenManager->linkParams((int)$quote->getId())
                    ),
                    $storeId
                );
            }
        } catch (LocalizedException) {
            // Store context is decorative; the address stays valid without it.
        }

        return $address;
    }

    /**
     * Resolves the recipient email with the same fallback order as the cron's
     * quote selection: quote.customer_email (set once payment info is entered,
     * or for a guest on Magento's own checkout as soon as a valid email is
     * typed — GuestCartEmail, PRO-3693), then the billing address email, then
     * the shipping address email, where other checkouts may keep a guest's
     * email (PRO-1275).
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
     * @param Item[] $items
     * @param array<int, Product> $products
     * @param array<int, array<string, string>> $details Each product's half
     *        of a slot, by product id, resolved on first use and shared by
     *        every cart of the store.
     * @return array<string, string>
     */
    private function productFields(array $items, array $products, array &$details): array
    {
        $fields = $this->blankSlots();

        $slot = 0;
        foreach ($items as $item) {
            if ($slot >= self::MAX_PRODUCTS) {
                $fields['over_10_products'] = 'true';
                break;
            }
            $slot++;
            $productId = (int)$item->getProduct()->getId();
            if (isset($products[$productId])) {
                $details[$productId] ??= $this->productDetails($products[$productId]);
            }

            foreach ($this->productRow($item, $details[$productId] ?? []) as $field => $value) {
                $fields[sprintf('product_%s_%d', $field, $slot)] = $value;
            }
        }

        return $fields;
    }

    /**
     * One slot: payload key suffix => value. A null item yields the blank row
     * every unused slot is prefilled with; a cart line whose product did not
     * load keeps the product half empty.
     *
     * @param array<string, string> $product productDetails() of the line's product.
     * @return array<string, string>
     */
    private function productRow(?Item $item, array $product): array
    {
        return [
            'name' => (string)$item?->getName(),
            'description' => $product['description'] ?? '',
            'image_url' => $product['image_url'] ?? '',
            'sku' => (string)$item?->getSku(),
            'quantity' => $item === null ? '' : (string)(float)$item->getQty(),
            'price' => $item === null
                ? ''
                : number_format((float)($item->getPriceInclTax() ?: $item->getPrice()), 2, '.', ''),
            'base_price' => $product['base_price'] ?? '',
        ];
    }

    /**
     * The product half of a slot: the same in every cart of the store.
     *
     * @return array<string, string>
     */
    private function productDetails(Product $product): array
    {
        $regular = (float)$product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();

        return [
            'description' => trim(strip_tags(
                (string)($product->getData('short_description') ?: $product->getData('description'))
            )),
            'image_url' => (string)$this->imageUrl($product),
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
            foreach (array_keys($this->productRow(null, [])) as $field) {
                for ($i = 1; $i <= self::MAX_PRODUCTS; $i++) {
                    self::$blankSlots[sprintf('product_%s_%d', $field, $i)] = '';
                }
            }
        }

        return self::$blankSlots;
    }

    /**
     * @param int[] $productIds
     * @return array<int, Product>
     */
    private function loadProducts(array $productIds, int $storeId): array
    {
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
