<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The store's own products for the engine's recommendation slots (contract
 * §15: "render with external_id, identify with sku"), at the current store
 * view, with the store's own name, image and price. A slot names its product
 * by `external_id` (the product id the catalog sync sends), else by `sku`
 * (the `mag-<id>` key of a product without a SKU names its id). A product
 * that is disabled, not visible in the catalog, not in this store's website
 * or not salable here is left out; nothing takes its place.
 *
 * Each link is the product's URL with the slot's recommendation id and the
 * `storefront` context, so the landing capture credits a purchase to the
 * storefront (§15 Attribution).
 */
class RecommendedProducts
{
    /** The §15 context a storefront slot link carries. */
    public const CONTEXT_STOREFRONT = 'storefront';

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly Visibility $visibility,
        private readonly CatalogConfig $catalogConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly AttributionManager $attributionManager
    ) {
    }

    /**
     * @param array<int, array{rec_id: string, external_id: string, sku: string}> $slots
     * @return array<int, array{product: Product, url: string}> in the slots' order
     */
    public function forSlots(array $slots): array
    {
        $keys = [];
        foreach ($slots as $index => $slot) {
            $keys[$index] = $this->productKey($slot);
        }
        $ids = array_column(array_filter($keys, static fn (array $key): bool => $key[0] === 'id'), 1);
        $skus = array_column(array_filter($keys, static fn (array $key): bool => $key[0] === 'sku'), 1);
        if ($ids === [] && $skus === []) {
            return [];
        }

        $storeId = (int)$this->storeManager->getStore()->getId();
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToSelect($this->catalogConfig->getProductAttributes())
            ->addMinimalPrice()
            ->addFinalPrice()
            ->addTaxPercents()
            ->addUrlRewrite()
            ->setVisibility($this->visibility->getVisibleInCatalogIds())
            ->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED])
            ->addAttributeToFilter([
                ['attribute' => 'entity_id', 'in' => $ids === [] ? [0] : $ids],
                ['attribute' => 'sku', 'in' => $skus === [] ? [''] : $skus],
            ]);

        $byId = [];
        $bySku = [];
        /** @var Product $product */
        foreach ($collection as $product) {
            $byId[(string)$product->getId()] = $product;
            $bySku[(string)$product->getSku()] = $product;
        }

        $config = $this->landingParams();
        $items = [];
        foreach ($slots as $index => $slot) {
            [$by, $value] = $keys[$index];
            $product = $by === 'id' ? ($byId[$value] ?? null) : ($bySku[$value] ?? null);
            if ($product === null || !$product->isSalable()) {
                continue;
            }
            $items[] = [
                'product' => $product,
                'url' => $this->link((string)$product->getProductUrl(), $slot['rec_id'], $config),
            ];
        }

        return $items;
    }

    /**
     * How a slot names its product: ['id', '42'] or ['sku', 'MJ01'].
     *
     * @param array{rec_id: string, external_id: string, sku: string} $slot
     * @return array{0: string, 1: string}
     */
    private function productKey(array $slot): array
    {
        if (ctype_digit($slot['external_id'])) {
            return ['id', (string)(int)$slot['external_id']];
        }
        if (preg_match('/^mag-(\d+)$/D', $slot['sku'], $match) === 1) {
            return ['id', $match[1]];
        }

        return ['sku', $slot['sku']];
    }

    /**
     * The landing parameter names the capture script reads (engine config wins).
     *
     * @return array{rec: string, context: string}
     */
    private function landingParams(): array
    {
        $config = $this->attributionManager->getClientConfig();

        return ['rec' => (string)$config['paramRecId'], 'context' => (string)$config['paramContext']];
    }

    /**
     * @param array{rec: string, context: string} $params
     */
    private function link(string $productUrl, string $recId, array $params): string
    {
        return $productUrl . (str_contains($productUrl, '?') ? '&' : '?') . http_build_query([
            $params['rec'] => $recId,
            $params['context'] => self::CONTEXT_STOREFRONT,
        ]);
    }
}
