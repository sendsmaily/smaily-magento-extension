<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Payload;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\ImageFactory as ImageHelperFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\StorefrontUrl;

/**
 * Product -> WireProduct (contract §3).
 *
 * One row per sellable catalog entry: visible products (parents included)
 * — configurable children resolve to their visible parent for
 * recommendations, matching the RSS feed behavior. Multilingual stores get
 * {lang: value} maps for name/description/product_url built from per-store
 * attribute values; the default store view is the canonical fallback.
 */
class CatalogPayloadBuilder
{
    /**
     * Contract §3 default when the store has no currency configured.
     * OrderPayloadBuilder reads it from here so the two payloads can never
     * disagree on what "no currency" means.
     */
    public const DEFAULT_CURRENCY = 'EUR';

    /** The `category_path` of a product with no real category (§3 requires one). */
    private const PLACEHOLDER_CATEGORY = 'uncategorized';

    /** Memoized: process-invariant, but read for every product in a backfill. */
    private ?StoreInterface $canonicalStore = null;

    private bool $canonicalStoreResolved = false;

    /** @var array<int, int|null> website id -> its default store view id (memoized per backfill page) */
    private array $websiteStoreIds = [];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StockRegistryStorage $stockRegistryStorage,
        private readonly ImageHelperFactory $imageHelperFactory,
        private readonly LanguageResolver $languageResolver,
        private readonly ParentProductResolver $parentProductResolver,
        private readonly Emulation $emulation,
        private readonly StorefrontUrl $storefrontUrl
    ) {
    }

    /**
     * Whether the product belongs in the engine catalog at all.
     */
    public function isIngestible(Product $product): bool
    {
        return (int)$product->getStatus() === Status::STATUS_ENABLED
            && in_array((int)$product->getVisibility(), [
                Visibility::VISIBILITY_IN_CATALOG,
                Visibility::VISIBILITY_BOTH,
                Visibility::VISIBILITY_IN_SEARCH,
            ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Product $product): array
    {
        $scopeStoreId = $this->storeIdForProduct($product);
        $priceProduct = $this->scopedTo($product, $scopeStoreId);
        $languageValues = $this->languageValues($product, $priceProduct, $scopeStoreId);
        $categoryPath = $this->categoryPath($product);

        $item = [
            'sku' => $this->sku($product),
            'name' => $languageValues['name'],
            'category_path' => $categoryPath ?? self::PLACEHOLDER_CATEGORY,
            'price' => round(
                (float)$priceProduct->getPriceInfo()->getPrice('final_price')->getAmount()->getValue(),
                4
            ),
            'currency' => $this->currency(),
            'in_stock' => $this->isInStock($product),
            'product_url' => $languageValues['product_url'],
            'external_id' => (string)$product->getId(),
            // The engine's primary structural exclusion signal (§3): gift
            // card types are excluded engine-side from this, never by us.
            'product_type' => (string)$product->getTypeId(),
            'is_virtual' => in_array($product->getTypeId(), [Type::TYPE_VIRTUAL, 'downloadable'], true),
            'is_downloadable' => $product->getTypeId() === 'downloadable',
        ];

        $regular = (float)$priceProduct->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();
        if ($regular > (float)$item['price']) {
            $item['compare_price'] = round($regular, 4);
            $saleUntil = (string)$product->getData('special_to_date');
            if ($saleUntil !== '') {
                $timestamp = strtotime($saleUntil);
                if ($timestamp !== false) {
                    $item['on_sale_until'] = gmdate('Y-m-d\TH:i:s\Z', $timestamp);
                }
            }
        }

        if ($languageValues['description'] !== null) {
            $item['description'] = $languageValues['description'];
        }

        $imageUrl = $this->imageUrl($product);
        if ($imageUrl !== null) {
            $item['image_url'] = $imageUrl;
        }

        $tags = $this->tags($product, (string)$item['category_path'], $categoryPath === null);
        if ($tags) {
            $item['tags'] = $tags;
        }

        $item['raw_attributes'] = [
            'type_id' => (string)$product->getTypeId(),
            'attribute_set_id' => (string)$product->getAttributeSetId(),
        ];

        return $item;
    }

    /**
     * Tombstone payload for deleted products: the engine never deletes,
     * an out-of-stock upsert removes the product from recommendations.
     *
     * @return array<string, mixed>
     */
    public function buildTombstone(Product $product): array
    {
        $item = $this->build($product);
        $item['in_stock'] = false;

        return $item;
    }

    private function sku(Product $product): string
    {
        $sku = trim((string)$product->getSku());

        return $sku !== '' ? $sku : 'mag-' . (int)$product->getId();
    }

    /**
     * name/description/product_url as plain strings (single-language) or
     * {lang: value} maps (multi-language storefronts).
     *
     * @return array{name: string|array<string, string>,
     *     description: string|array<string, string>|null,
     *     product_url: string|array<string, string>}
     */
    private function languageValues(Product $product, Product $scopedProduct, int $scopeStoreId): array
    {
        $storesByLanguage = $this->storesByLanguage($product);

        if (count($storesByLanguage) <= 1) {
            $storeId = $storesByLanguage ? (int)reset($storesByLanguage) : $scopeStoreId;

            return [
                'name' => (string)$product->getName(),
                'description' => $this->description($product),
                'product_url' => $this->productUrl(
                    $storeId === $scopeStoreId ? $scopedProduct : $this->scopedTo($product, $storeId),
                    $storeId
                ),
            ];
        }

        $names = [];
        $descriptions = [];
        $urls = [];
        foreach ($storesByLanguage as $language => $storeId) {
            try {
                $storeProduct = $this->productRepository->getById((int)$product->getId(), false, $storeId);
            } catch (NoSuchEntityException) {
                continue;
            }
            if (!$storeProduct instanceof Product) {
                continue;
            }
            $names[$language] = (string)$storeProduct->getName();
            $description = $this->description($storeProduct);
            if ($description !== null) {
                $descriptions[$language] = $description;
            }
            $urls[$language] = $this->productUrl($storeProduct, (int)$storeId);
        }

        return [
            'name' => $names ?: (string)$product->getName(),
            'description' => $descriptions ?: $this->description($product),
            'product_url' => $urls ?: $this->productUrl($scopedProduct, $scopeStoreId),
        ];
    }

    /**
     * @return array<string, int> language code -> representative store ID
     */
    private function storesByLanguage(Product $product): array
    {
        $websiteIds = array_map('intval', (array)$product->getWebsiteIds());
        $byLanguage = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ($websiteIds && !in_array((int)$store->getWebsiteId(), $websiteIds, true)) {
                continue;
            }
            $language = $this->languageResolver->forStore((int)$store->getId());
            if ($language !== '' && !isset($byLanguage[$language])) {
                $byLanguage[$language] = (int)$store->getId();
            }
        }

        return $byLanguage;
    }

    /**
     * Frontend-scoped product URL.
     *
     * `getProductUrl()` resolves against the *current* app environment, so in
     * a CLI/cron context (backfill jobs, cron flushers) the generated URL can
     * embed the invoking PHP entry script path (observed:
     * `.../run-job.php/some-product.html`) — a link that 404s in an email.
     * Forcing frontend store emulation makes the URL come out exactly as the
     * storefront would render it, in any execution context (web/CLI/cron).
     * Emulation is always stopped, even on failure (try/finally).
     *
     * The product must already be loaded at `$storeId`: Magento's URL model
     * reads the rewrite and the base URL off the product's OWN store, so a
     * canonical-scoped product would keep emitting the canonical store's
     * link no matter which store is emulated around it (PRO-1458).
     *
     * A store with a separate storefront gets the link on the storefront's
     * address (PRO-3660).
     */
    private function productUrl(Product $product, int $storeId): string
    {
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $url = (string)$product->getProductUrl();
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }

        return $this->storefrontUrl->apply($url, $storeId);
    }

    /**
     * The single canonical store scope for catalog ingest (PRO-1352/1353):
     * one Magento installation is one engine tenant, and this plugin — not
     * the engine — is responsible for always sending one consistent scope's
     * price/URL/language, never whatever scope a CLI/cron context or an
     * admin's store-view switcher happened to resolve. The default store
     * view of the default website is the same "default scope" concept
     * already used elsewhere in the module (`Engine\Client`'s base-URL
     * fallback, `Multilingual\AccountResolver`'s default account).
     * `EngineCatalogProcessor` calls this same method to scope the backfill
     * collection, so both ingest paths can never disagree.
     *
     * One exception, and only one (PRO-1458): a product not assigned to the
     * canonical store's website has no price there to read, so it is priced
     * and linked at a website it actually belongs to — see
     * `storeIdForProduct()`.
     *
     * The optional `currency` field (contract v1.7.0) does NOT relax this:
     * §3 keeps "one currency per tenant" as the assumed model, and the
     * catalog row is keyed on `sku` per tenant — a second store scope would
     * upsert onto the SAME row, so it can only overwrite, never coexist.
     * Per-scope catalog rows need a tenant per scope, which is the
     * multi-website RFC's Phase 4, not this field (PRO-1762).
     */
    public function canonicalStoreId(): int
    {
        return (int)$this->canonicalStore()?->getId();
    }

    private function canonicalStore(): ?StoreInterface
    {
        if (!$this->canonicalStoreResolved) {
            $this->canonicalStoreResolved = true;
            try {
                $this->canonicalStore = $this->storeManager->getDefaultStoreView();
            } catch (NoSuchEntityException) {
                $this->canonicalStore = null;
            }
        }

        return $this->canonicalStore;
    }

    /**
     * The currency the `price` we send is denominated in (contract §3,
     * v1.7.0). The canonical store's DEFAULT DISPLAY currency, not its base
     * currency: Magento's price readers (`RegularPrice`/`BasePrice`, which
     * `final_price` resolves through) already convert the stored base amount
     * into the store's display currency, so on a store whose base and display
     * currencies differ, labelling the number with the base code would state
     * a price that was never charged in it.
     */
    private function currency(): string
    {
        $store = $this->canonicalStore();
        $code = $store instanceof Store ? (string)$store->getDefaultCurrencyCode() : '';

        return $code !== '' ? $code : self::DEFAULT_CURRENCY;
    }

    /**
     * The store scope this product's price and URL are read at (PRO-1458).
     *
     * Normally the canonical store — but a product that is not assigned to
     * the canonical store's website has no price of its own there at all,
     * so reading it there reports another website's (or the admin default's)
     * number for a product that website never sells. Such a product is read
     * at the default store view of the first website it IS assigned to
     * (lowest website id, so the choice is stable across runs). A product
     * assigned to no website at all keeps the canonical scope — unchanged
     * behaviour: it is still built and still ingested, never skipped.
     *
     * This is still ONE payload per product (the PRO-1352/1353 tradeoff): a
     * product on several websites is priced at the canonical one whenever it
     * belongs there, and per-website rows remain the multi-website RFC's
     * Phase 4 (PRO-1762).
     */
    private function storeIdForProduct(Product $product): int
    {
        $websiteIds = array_map('intval', (array)$product->getWebsiteIds());
        $canonicalWebsiteId = (int)($this->canonicalStore()?->getWebsiteId() ?? 0);
        if (!$websiteIds || in_array($canonicalWebsiteId, $websiteIds, true)) {
            return $this->canonicalStoreId();
        }

        sort($websiteIds);
        foreach ($websiteIds as $websiteId) {
            $storeId = $this->websiteStoreId($websiteId);
            if ($storeId !== null) {
                return $storeId;
            }
        }

        return $this->canonicalStoreId();
    }

    /**
     * A website's own default store view, or null when it has none (a
     * website without a default store can't price anything).
     */
    private function websiteStoreId(int $websiteId): ?int
    {
        if (!array_key_exists($websiteId, $this->websiteStoreIds)) {
            try {
                $website = $this->storeManager->getWebsite($websiteId);
            } catch (NoSuchEntityException) {
                $website = null;
            }
            $storeId = $website instanceof Website ? (int)($website->getDefaultStore()?->getId() ?? 0) : 0;
            $this->websiteStoreIds[$websiteId] = $storeId > 0 ? $storeId : null;
        }

        return $this->websiteStoreIds[$websiteId];
    }

    /**
     * The product re-scoped to the store its price and URL are read at, so
     * both are always read consistently regardless of which scope the caller
     * loaded the product in (backfill's collection already loads at the
     * canonical scope, so this is a no-op for canonical-website products).
     */
    private function scopedTo(Product $product, int $storeId): Product
    {
        if ((int)$product->getStoreId() === $storeId) {
            return $product;
        }

        try {
            $scoped = $this->productRepository->getById((int)$product->getId(), false, $storeId);
        } catch (NoSuchEntityException) {
            return $product;
        }

        return $scoped instanceof Product ? $scoped : $product;
    }

    private function description(Product $product): ?string
    {
        $raw = (string)($product->getData('short_description') ?: $product->getData('description'));
        $text = trim(strip_tags($raw));
        if ($text === '') {
            return null;
        }

        // Engine truncates at 500 anyway; trim client-side to keep payloads lean.
        return mb_substr($text, 0, 500);
    }

    /**
     * The slug path of the product's deepest category.
     *
     * Null when the product has no real category: none assigned, none
     * loadable, or only the root.
     *
     * @param Product $product
     * @return string|null
     */
    private function categoryPath(Product $product): ?string
    {
        $deepest = null;
        $deepestLevel = -1;
        foreach (array_map('intval', (array)$product->getCategoryIds()) as $categoryId) {
            try {
                $category = $this->categoryRepository->get($categoryId);
            } catch (NoSuchEntityException) {
                continue;
            }
            if ((int)$category->getLevel() > $deepestLevel) {
                $deepest = $category;
                $deepestLevel = (int)$category->getLevel();
            }
        }
        if ($deepest === null) {
            return null;
        }

        $segments = [];
        $pathIds = array_slice(explode('/', (string)$deepest->getPath()), 2); // skip root + default
        foreach (array_map('intval', $pathIds) as $pathId) {
            try {
                $pathCategory = $this->categoryRepository->get($pathId);
            } catch (NoSuchEntityException) {
                continue;
            }
            if (!$pathCategory instanceof \Magento\Catalog\Model\Category) {
                continue;
            }
            $slug = (string)($pathCategory->getData('url_key') ?: $this->slugify((string)$pathCategory->getName()));
            if ($slug !== '') {
                $segments[] = $slug;
            }
        }

        return $segments ? implode('/', $segments) : null;
    }

    private function isInStock(Product $product): bool
    {
        // The stock registry memoises the item per request, and MSI mirrors
        // its quantities onto the legacy row with direct SQL and so never
        // invalidates that memo — a shipment that sold the last unit out was
        // published as still in stock until this drop (caught on the sandbox,
        // not by the unit tests). Dropping it here, at the only read, keeps
        // every caller correct by construction: live hooks, the delete
        // tombstone and the backfill/nightly-resync pages alike.
        $productId = (int)$product->getId();
        $this->stockRegistryStorage->removeStockItem($productId);

        try {
            return (bool)$this->stockRegistry->getStockItem($productId)->getIsInStock();
        } catch (\Exception) {
            return true;
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

    /**
     * @return array<string, string>
     */
    private function tags(Product $product, string $categoryPath, bool $categoryDefaulted): array
    {
        $tags = [
            'category_path' => $categoryPath,
            // §3 identity: the platform parent product id — the configurable
            // parent's entity id for a child, the product's own otherwise.
            // Keys §3b product-level removal (PRO-1231); the `sku` keying
            // itself is intentionally unchanged (PRO-1267: order lines and
            // catalog rows must keep keying consistently).
            'product_id' => $this->parentProductResolver->productIdOf((int)$product->getId()),
        ];

        $brand = $product->getAttributeText('manufacturer');
        if (is_string($brand) && trim($brand) !== '') {
            $tags['brand'] = trim($brand);
        }

        // §3 (v1.6.0): the category_path is a placeholder, so the engine
        // derives nothing from its slug. Omit-on-false: never "false".
        if ($categoryDefaulted) {
            $tags['category_defaulted'] = 'true';
        }

        return $tags;
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $value), '-'));

        return $slug;
    }
}
