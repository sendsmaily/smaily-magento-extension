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
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\StorefrontScript;
use Smaily\Connect\Model\StorefrontUrl;

/**
 * Product -> WireProduct (contract §3).
 *
 * One row per sellable catalog entry: visible products (parents included)
 * — configurable children resolve to their visible parent for
 * recommendations, matching the RSS feed behavior. Multilingual stores get
 * {lang: value} maps for name/description/product_url built from per-store
 * attribute values; the default store view is the canonical fallback.
 *
 * Every row also lists the websites that sell the product, with its link in
 * each of their store-view languages (`websites`, contract v1.21.0 §3
 * "Several websites", CON-253): one engine store serves every website, and
 * the engine sends each customer the links of their own website and never a
 * product their website does not sell.
 *
 * A row is built for one Campaign Intelligence connection (CON-237): its
 * `websites`, its language map and the website it is priced at are the
 * connection's own websites only, and its price, link and currency are
 * read at the connection's canonical store view (see storeIdForProduct()).
 * A connection that serves every website — every store run as one store —
 * gets the row it always got.
 */
class CatalogPayloadBuilder
{
    /**
     * Contract §3 default when the store has no currency configured.
     * OrderPayloadBuilder reads it from here so the two payloads can never
     * disagree on what "no currency" means.
     */
    public const DEFAULT_CURRENCY = 'EUR';

    /**
     * The attributes build() and Magento's price classes read off a product.
     * A collection that loads products for build() selects exactly these, so
     * a product it loads is sent as the product save sends it (PRO-3692):
     * SpecialPrice checks the sale window and the payload's `on_sale_until`
     * is `special_to_date`; the bundle price classes price a bundle as fixed
     * or dynamic by `price_type`; the tax adjustment of a price amount reads
     * `tax_class_id`.
     */
    public const PRODUCT_ATTRIBUTES = [
        'name', 'status', 'visibility', 'price', 'special_price', 'special_from_date',
        'special_to_date', 'price_type', 'tax_class_id',
        'short_description', 'description', 'url_key', 'image',
        'small_image', 'thumbnail', 'manufacturer',
    ];

    /** The `category_path` of a product with no real category (§3 requires one). */
    private const PLACEHOLDER_CATEGORY = 'uncategorized';

    /** Memoized: process-invariant, but read for every product in a backfill. */
    private ?StoreInterface $canonicalStore = null;

    private bool $canonicalStoreResolved = false;

    /** @var array<int, int|null> website id -> its default store view id (memoized per backfill page) */
    private array $websiteStoreIds = [];

    /**
     * Memoized like $websiteStoreIds: see websiteIndex().
     *
     * @var array<int, array{code: string|null, stores: array<int, int>, languages: array<string, int>}>|null
     */
    private ?array $websiteIndex = null;

    /** @var array<int, string> store view id -> the product's link there, for the row being built */
    private array $storeUrls = [];

    /** @var array<int, StoreInterface|null> store view id -> the store view (memoized) */
    private array $stores = [];

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
        private readonly StorefrontUrl $storefrontUrl,
        private readonly StorefrontScript $storefrontScript
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
     * The product's row for one connection's engine store.
     *
     * @return array<string, mixed>
     */
    public function build(Product $product, Connection $connection): array
    {
        $this->storeUrls = [];
        $scopeStoreId = $this->storeIdForProduct($product, $connection);
        $priceProduct = $this->scopedTo($product, $scopeStoreId);
        $languageValues = $this->languageValues($product, $priceProduct, $scopeStoreId, $connection);
        // A variant without a category of its own takes its configurable
        // parent's (PRO-3714); only when neither has one is it defaulted.
        $categoryPath = $this->categoryPath((array)$product->getCategoryIds())
            ?? $this->categoryPath($this->parentProductResolver->parentCategoryIds((int)$product->getId()));

        $item = [
            'sku' => self::sku($product),
            'name' => $languageValues['name'],
            'category_path' => $categoryPath ?? self::PLACEHOLDER_CATEGORY,
            'price' => round(
                (float)$priceProduct->getPriceInfo()->getPrice('final_price')->getAmount()->getValue(),
                4
            ),
            'currency' => $this->currency($connection),
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

        $imageUrl = $this->imageUrl($product, $scopeStoreId);
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

        // Always sent, also on a store with one website: a row without it
        // reads as sold on every website (§3 "Send the whole row").
        $item['websites'] = $this->websites($product, $priceProduct, $scopeStoreId, $connection);

        return $item;
    }

    /**
     * Tombstone payload for deleted products: the engine never deletes,
     * an out-of-stock upsert removes the product from recommendations.
     *
     * @return array<string, mixed>
     */
    public function buildTombstone(Product $product, Connection $connection): array
    {
        $item = $this->build($product, $connection);
        $item['in_stock'] = false;

        return $item;
    }

    /**
     * The product's item in the nightly catalog manifest (contract §3c,
     * PRO-3854): the `sku` and `in_stock` its catalog row carries, read the
     * way build() and the tombstone read them — a product that left the
     * sellable set (CatalogIngest sends its tombstone) is out of stock. Null
     * for a disabled product, which the list leaves out so the engine stops
     * recommending it. Reads only `status`, `visibility`, `sku` and the
     * stock row, so a light collection can feed it
     * (CatalogProductLoader::loadForManifest()).
     *
     * @return array{sku: string, in_stock: bool}|null
     */
    public function manifestItem(Product $product): ?array
    {
        if ((int)$product->getStatus() !== Status::STATUS_ENABLED) {
            return null;
        }

        return [
            'sku' => self::sku($product),
            'in_stock' => $this->isIngestible($product) && $this->isInStock($product),
        ];
    }

    /**
     * The product's engine key: its SKU, or `mag-<entity_id>` when the SKU
     * is empty (contract §3, "Same key from every path"). The storefront
     * page context keys `product_view` with it too (PRO-2461), so a product
     * browses under the key its catalog row and order lines carry.
     */
    public static function sku(Product $product): string
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
    private function languageValues(
        Product $product,
        Product $scopedProduct,
        int $scopeStoreId,
        Connection $connection
    ): array {
        $storesByLanguage = $this->storesByLanguage($product, $scopeStoreId, $connection);

        if (count($storesByLanguage) <= 1) {
            $storeId = $storesByLanguage ? (int)reset($storesByLanguage) : $scopeStoreId;

            return [
                'name' => (string)$product->getName(),
                'description' => $this->description($product),
                'product_url' => $this->urlAt(
                    $storeId === $scopeStoreId ? $scopedProduct : $this->scopedTo($product, $storeId),
                    $storeId
                ),
            ];
        }

        $names = [];
        $descriptions = [];
        $urls = [];
        foreach ($storesByLanguage as $language => $storeId) {
            $storeProduct = $this->loadAt($product, $storeId);
            if ($storeProduct === null) {
                continue;
            }
            $names[$language] = (string)$storeProduct->getName();
            $description = $this->description($storeProduct);
            if ($description !== null) {
                $descriptions[$language] = $description;
            }
            $urls[$language] = $this->urlAt($storeProduct, $storeId);
        }

        return [
            'name' => $names ?: (string)$product->getName(),
            'description' => $descriptions ?: $this->description($product),
            'product_url' => $urls ?: $this->urlAt($scopedProduct, $scopeStoreId),
        ];
    }

    /**
     * The store view whose name, description and link the row carries for
     * each language: the language's store view on the website the row is
     * priced at, then on the product's other websites in id order (CON-253).
     * Before, it was the language's first store view on any of them, so
     * where two websites share a language the row could carry the other
     * website's link. A product on no website takes every website's. Only
     * the connection's own websites count (CON-237).
     *
     * @return array<string, int> language code -> representative store ID
     */
    private function storesByLanguage(Product $product, int $scopeStoreId, Connection $connection): array
    {
        $index = $this->connectionWebsites($this->websiteIndex(), $connection);
        $assigned = array_map('intval', (array)$product->getWebsiteIds());
        $websiteIds = $assigned ? array_values(array_intersect($assigned, array_keys($index))) : array_keys($index);
        sort($websiteIds);
        foreach ($websiteIds as $position => $websiteId) {
            if (in_array($scopeStoreId, $index[$websiteId]['stores'], true)) {
                unset($websiteIds[$position]);
                array_unshift($websiteIds, $websiteId);
                break;
            }
        }

        $byLanguage = [];
        foreach ($websiteIds as $websiteId) {
            $byLanguage += $index[$websiteId]['languages'];
        }

        return $byLanguage;
    }

    /**
     * The websites that sell the product, each with the product's link in
     * every language of its store views (contract v1.21.0 §3 "Several
     * websites", CON-253): the websites the product is assigned to, less a
     * website where it is disabled or not visible in each store view. A
     * website whose store views name no language gets the link of its
     * default store view, as a plain string. Empty (`{}` on the wire) when
     * no website sells the product. Only the connection's own websites are
     * listed (CON-237): a website of another engine store is not this
     * engine store's to send.
     *
     * @return array<string, array{product_url: string|array<string, string>}>
     */
    private function websites(
        Product $product,
        Product $scopedProduct,
        int $scopeStoreId,
        Connection $connection
    ): array {
        $index = $this->connectionWebsites($this->websiteIndex(), $connection);
        $websiteIds = array_map('intval', (array)$product->getWebsiteIds());
        sort($websiteIds);

        $websites = [];
        foreach ($websiteIds as $websiteId) {
            $website = $index[$websiteId] ?? null;
            if ($website === null || $website['code'] === null) {
                continue;
            }
            $stores = $website['languages'] ?: ['' => $website['stores'][0] ?? 0];
            $urls = [];
            foreach ($stores as $language => $storeId) {
                $storeProduct = $storeId === $scopeStoreId ? $scopedProduct : $this->loadAt($product, $storeId);
                if ($storeProduct === null || !$this->isIngestible($storeProduct)) {
                    continue;
                }
                $url = $this->urlAt($storeProduct, $storeId);
                if ($url !== '') {
                    $urls[$language] = $url;
                }
            }
            if ($urls) {
                $websites[$website['code']] = ['product_url' => $website['languages'] ? $urls : reset($urls)];
            }
        }

        return $websites;
    }

    /**
     * The part of the website index a connection serves: the whole index
     * for a connection that serves every website.
     *
     * @param array<int, array{code: string|null, stores: array<int, int>, languages: array<string, int>}> $index
     * @return array<int, array{code: string|null, stores: array<int, int>, languages: array<string, int>}>
     */
    private function connectionWebsites(array $index, Connection $connection): array
    {
        if ($connection->servesAllWebsites()) {
            return $index;
        }

        return array_intersect_key($index, array_flip($connection->websiteIds()));
    }

    /**
     * The installation's websites, by id, from their active store views: the
     * website's code (null when Magento has none the engine accepts, 1–64
     * characters), its store views (its default store view first, then the
     * others in Magento's order) and the first of them for each language.
     *
     * @return array<int, array{code: string|null, stores: array<int, int>, languages: array<string, int>}>
     */
    private function websiteIndex(): array
    {
        if ($this->websiteIndex !== null) {
            return $this->websiteIndex;
        }

        $codes = [];
        $defaultStoreIds = [];
        foreach ((array)$this->storeManager->getWebsites() as $website) {
            $code = (string)$website->getCode();
            $codes[(int)$website->getId()] = $code !== '' && strlen($code) <= 64 ? $code : null;
            if ($website instanceof Website) {
                $defaultStoreIds[(int)$website->getId()] = (int)($website->getDefaultStore()?->getId() ?? 0);
            }
        }

        $storesByWebsite = [];
        foreach ((array)$this->storeManager->getStores() as $store) {
            // A store view switched off has no storefront to link to.
            $isActive = $store->getIsActive();
            if ($isActive !== null && !(bool)$isActive) {
                continue;
            }
            $storesByWebsite[(int)$store->getWebsiteId()][] = (int)$store->getId();
        }

        $index = [];
        foreach ($storesByWebsite as $websiteId => $storeIds) {
            $defaultStoreId = $defaultStoreIds[$websiteId] ?? 0;
            if (in_array($defaultStoreId, $storeIds, true)) {
                $storeIds = array_values(array_merge([$defaultStoreId], array_diff($storeIds, [$defaultStoreId])));
            }
            $languages = [];
            foreach ($storeIds as $storeId) {
                $language = $this->languageResolver->forStore($storeId);
                if ($language !== '' && !isset($languages[$language])) {
                    $languages[$language] = $storeId;
                }
            }
            $index[$websiteId] = [
                'code' => $codes[$websiteId] ?? null,
                'stores' => $storeIds,
                'languages' => $languages,
            ];
        }

        return $this->websiteIndex = $index;
    }

    /**
     * The product loaded at a store view, or null when it cannot be.
     */
    private function loadAt(Product $product, int $storeId): ?Product
    {
        try {
            $storeProduct = $this->productRepository->getById((int)$product->getId(), false, $storeId);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $storeProduct instanceof Product ? $storeProduct : null;
    }

    /**
     * The product's link at a store view, built once per row: the row's
     * language map and its websites name the same store views.
     */
    private function urlAt(Product $storeProduct, int $storeId): string
    {
        return $this->storeUrls[$storeId] ??= $this->productUrl($storeProduct, $storeId);
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
     * Emulation does not change the running script, though: with web server
     * rewrites off, Magento puts its name in the link — see
     * StorefrontScript (PRO-3731).
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

        return $this->storefrontUrl->apply($this->storefrontScript->apply($url, $storeId), $storeId);
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
     * Per-website price inside one engine store is the engine's to add, not
     * this field (contract 1.21.0 gives per-website links only, CON-253):
     * one Smaily account is one engine store, and websites that share an
     * account share it (2026-10-28, CON-237).
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
     * a price that was never charged in it. For a connection, its canonical
     * store's (connectionStore(), CON-237).
     */
    private function currency(Connection $connection): string
    {
        $store = $this->connectionStore($connection);
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
     * belongs there. Per-website links and the websites that sell it go in
     * `websites` (contract 1.21.0, CON-253); per-website price and stock
     * are not in the contract: one Smaily account is one engine store, and
     * websites that share an account share it (2026-10-28, CON-237).
     *
     * For a connection (CON-237) the same rule runs on its own websites:
     * its canonical store view (connectionStore()) when the product is on
     * that store view's website, else the default store view of the
     * lowest-id website of the connection the product is on. A connection
     * that serves every website reads at the installation's canonical
     * store, as before.
     */
    private function storeIdForProduct(Product $product, Connection $connection): int
    {
        $scopeStore = $this->connectionStore($connection);
        $scopeStoreId = (int)$scopeStore?->getId();
        $websiteIds = array_map('intval', (array)$product->getWebsiteIds());
        $canonicalWebsiteId = (int)($scopeStore?->getWebsiteId() ?? 0);
        if (!$websiteIds || in_array($canonicalWebsiteId, $websiteIds, true)) {
            return $scopeStoreId;
        }

        if (!$connection->servesAllWebsites()) {
            $websiteIds = array_values(array_intersect($websiteIds, $connection->websiteIds()));
        }
        sort($websiteIds);
        foreach ($websiteIds as $websiteId) {
            $storeId = $this->websiteStoreId($websiteId);
            if ($storeId !== null) {
                return $storeId;
            }
        }

        return $scopeStoreId;
    }

    /**
     * The store view a connection's rows are read at: the installation's
     * canonical store for a connection that serves every website (its
     * canonical store view is that store), else the connection's own
     * canonical store view — the installation's when it has none or it
     * cannot be read.
     */
    private function connectionStore(Connection $connection): ?StoreInterface
    {
        $storeId = $connection->canonicalStoreId();
        if ($connection->servesAllWebsites() || $storeId === null) {
            return $this->canonicalStore();
        }
        if (!array_key_exists($storeId, $this->stores)) {
            try {
                $this->stores[$storeId] = $this->storeManager->getStore($storeId);
            } catch (NoSuchEntityException) {
                $this->stores[$storeId] = null;
            }
        }

        return $this->stores[$storeId] ?? $this->canonicalStore();
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
     * The slug path of the deepest of the given categories (a product's
     * category ids).
     *
     * Null when there is no real category: none given, none loadable, or
     * only the root.
     *
     * @param array<int|string> $categoryIds
     * @return string|null
     */
    private function categoryPath(array $categoryIds): ?string
    {
        $deepest = null;
        $deepestLevel = -1;
        foreach (array_map('intval', $categoryIds) as $categoryId) {
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
        // tombstone and the catalog import pages alike.
        $productId = (int)$product->getId();
        $this->stockRegistryStorage->removeStockItem($productId);

        try {
            return (bool)$this->stockRegistry->getStockItem($productId)->getIsInStock();
        } catch (\Exception) {
            return true;
        }
    }

    /**
     * The product page's large image, as the storefront of `$storeId` shows
     * it (PRO-3731). The image size comes from the theme in effect and a
     * placeholder's path names the area, so outside frontend emulation a
     * cron build asked the crontab area — which has no theme — and got a
     * placeholder that does not open, even for a product with an image (a
     * save in the admin got the same from the admin theme). A separate
     * emulation from productUrl()'s, never nested in it: Magento allows one
     * level.
     */
    private function imageUrl(Product $product, int $storeId): ?string
    {
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $url = $this->imageHelperFactory->create()
                ->init($product, 'product_page_image_large')
                ->getUrl();

            return $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        } finally {
            $this->emulation->stopEnvironmentEmulation();
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
