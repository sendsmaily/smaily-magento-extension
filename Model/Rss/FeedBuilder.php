<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Rss;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Helper\ImageFactory as ImageHelperFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\StorefrontUrl;

/**
 * Builds the Smaily product RSS 2.0 feed (smly namespace) used by the
 * Smaily template editor's RSS block.
 *
 * Prices are final/regular prices including tax adjustments, matching what
 * shoppers see in the storefront. Only products visible in the catalog are
 * listed, so configurable variants resolve naturally to their visible
 * parent — this fixes the legacy fuzzy category-name filter and invisible
 * product issues (upstream #48/#49/#72).
 */
class FeedBuilder
{
    public const SMLY_NAMESPACE = 'https://sendsmaily.net/schema/editor/rss.xsd';
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 250;

    public const SORT_FIELDS = ['created_at', 'updated_at', 'name', 'price'];

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly Visibility $visibility,
        private readonly ImageHelperFactory $imageHelperFactory,
        private readonly Logger $logger,
        private readonly StorefrontUrl $storefrontUrl
    ) {
    }

    /**
     * The limit, sort field and sort order as the feed applies them: an
     * out-of-range limit is clamped, an unknown field or order falls back to
     * the default. The feed's cache key is built from these, so requests that
     * differ only in invalid values share one cache entry.
     *
     * @return array{int, string, string}
     */
    public static function normalize(int $limit, string $sortBy, string $sortOrder): array
    {
        return [
            max(1, min($limit ?: self::DEFAULT_LIMIT, self::MAX_LIMIT)),
            in_array($sortBy, self::SORT_FIELDS, true) ? $sortBy : 'created_at',
            strtolower($sortOrder) === 'asc' ? 'ASC' : 'DESC',
        ];
    }

    /**
     * Build the RSS XML for a store.
     */
    public function build(
        StoreInterface $store,
        ?int $categoryId,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): string {
        [$limit, $sortBy, $sortOrder] = self::normalize($limit, $sortBy, $sortOrder);

        $rss = new XmlElement(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rss xmlns:smly="' . self::SMLY_NAMESPACE . '" version="2.0"/>'
        );
        $channel = $rss->addChild('channel');
        $channel->addChild('title', htmlspecialchars((string)$store->getName()));
        $channel->addChild('link', htmlspecialchars((string)$store->getBaseUrl()));
        $channel->addChild('description', 'Product feed for Smaily templates');
        $channel->addChild('lastBuildDate', gmdate(DATE_RSS));

        foreach ($this->loadProducts($store, $categoryId, $limit, $sortBy, $sortOrder) as $product) {
            $this->addItem($channel, $product, (int)$store->getId());
        }

        return (string)$rss->asXML();
    }

    /**
     * @return Product[]
     */
    private function loadProducts(
        StoreInterface $store,
        ?int $categoryId,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId((int)$store->getId())
            ->addAttributeToSelect(['name', 'description', 'short_description', 'image', 'small_image', 'thumbnail'])
            ->addAttributeToFilter(
                'status',
                ['eq' => \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED]
            )
            ->setVisibility($this->visibility->getVisibleInCatalogIds())
            ->addStoreFilter((int)$store->getId())
            ->addUrlRewrite()
            ->addPriceData()
            ->setOrder($sortBy, $sortOrder)
            ->setPageSize($limit);

        if ($categoryId !== null && $categoryId > 0) {
            try {
                $category = $this->categoryRepository->get($categoryId, (int)$store->getId());
                if ($category instanceof \Magento\Catalog\Model\Category) {
                    $collection->addCategoryFilter($category);
                }
            } catch (NoSuchEntityException) {
                $this->logger->debug('RSS category not found', ['category_id' => $categoryId]);

                return [];
            }
        }

        $products = [];
        foreach ($collection->getItems() as $product) {
            if ($product instanceof Product) {
                $products[] = $product;
            }
        }

        return $products;
    }

    private function addItem(XmlElement $channel, Product $product, int $storeId): void
    {
        // A store with a separate storefront links there (PRO-3660).
        $productUrl = $this->storefrontUrl->apply($product->getProductUrl(), $storeId);

        /** @var XmlElement $item */
        $item = $channel->addChild('item');
        $item->addChild('title', htmlspecialchars((string)$product->getName()));
        $item->addChild('link', htmlspecialchars($productUrl));
        $guid = $item->addChild('guid', htmlspecialchars($productUrl));
        $guid->addAttribute('isPermaLink', 'true');

        $createdAt = (string)$product->getCreatedAt();
        if ($createdAt !== '') {
            $item->addChild('pubDate', gmdate(DATE_RSS, (int)strtotime($createdAt)));
        }

        $description = trim(strip_tags(
            (string)($product->getData('short_description') ?: $product->getData('description'))
        ));
        if ($description !== '') {
            $item->addChildWithCdata('description', $description);
        }

        $imageUrl = $this->imageUrl($product);
        if ($imageUrl !== null) {
            $enclosure = $item->addChild('enclosure');
            $enclosure->addAttribute('url', $imageUrl);
        }

        $finalPrice = (float)$product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
        $regularPrice = (float)$product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();

        $item->addChild('price', number_format($finalPrice, 2, '.', ''), self::SMLY_NAMESPACE);
        if ($regularPrice > $finalPrice) {
            $item->addChild('old_price', number_format($regularPrice, 2, '.', ''), self::SMLY_NAMESPACE);
            $discount = (int)ceil((1 - $finalPrice / $regularPrice) * 100);
            $item->addChild('discount', sprintf('-%d%%', $discount), self::SMLY_NAMESPACE);
        }
    }

    private function imageUrl(Product $product): ?string
    {
        try {
            $url = $this->imageHelperFactory->create()
                ->init($product, 'product_page_image_large')
                ->getUrl();

            return $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
