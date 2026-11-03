<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;

/**
 * How the websites of a 2+-website install are run (CON-279, owner decision
 * 2026-11-03): one store in several versions, set up once for the whole
 * store (CON-179), or separate stores, each website set up on its own as a
 * single store is — its own Smaily account, Contacts settings and
 * automations, saved at its website scope.
 *
 * The shape is read from the data: the websites hold separate Smaily
 * accounts and no store-wide one (WebsiteOverrides::holdsSeparateAccounts();
 * a 2.8.x upgrade or a store set up on an earlier release candidate, CON-192)
 * — or the store was set up as separate stores, which one default-scope mark
 * remembers (XML_PATH_SEPARATE_STORES). The data alone cannot tell separate
 * stores whose websites share one Smaily account (right after the change
 * from one store, or by the merchant's choice) from a store whose websites
 * all hold the store's one account as their own (a 2.8.x upgrade often
 * saved it on every website, and that store is one store), nor say what the
 * merchant picked before the first website is saved. Everything else is
 * one store, and a single-website install is never separate stores.
 *
 * A runtime service, not an admin one: the admin pages and their saves read
 * it, and so can crons and the storefront (isSeparateStores()). Leaving
 * separate stores dispatches EVENT_JOINED right before the websites' own
 * values are removed — the seam for whatever each website held of its own
 * outside these settings (today nothing listens).
 */
class StoreLayout
{
    /** The mark of a store set up as separate stores (default scope, '1'). */
    public const XML_PATH_SEPARATE_STORES = 'smaily_connect/internal/separate_stores';

    /**
     * Dispatched by toOneStore() right before every website's own values
     * are removed, with `website_ids` (every website) and `website_id`
     * (the website whose values become the values for all websites).
     */
    public const EVENT_JOINED = 'smaily_connect_store_layout_joined';

    public const ONE_STORE = 'one';
    public const SEPARATE_STORES = 'separate';

    /** The sections every separate store holds as its own. */
    private const SECTIONS = [
        WebsiteOverrides::CONNECTION,
        WebsiteOverrides::SUBSCRIBERS,
        WebsiteOverrides::AUTOMATIONS,
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly WriterInterface $configWriter,
        private readonly StoreManagerInterface $storeManager,
        private readonly WebsiteOverrides $overrides,
        private readonly ManagerInterface $eventManager
    ) {
    }

    /**
     * Whether the websites are set up as separate stores.
     */
    public function isSeparateStores(): bool
    {
        if (count($this->storeManager->getWebsites()) < 2) {
            return false;
        }

        return $this->isMarked() || $this->overrides->holdsSeparateAccounts();
    }

    /**
     * Whether the shape is known before the initial setup asks for it: the
     * websites are separate stores, or a Smaily account is saved for the
     * whole store (one store).
     */
    public function isKnown(): bool
    {
        return $this->isSeparateStores() || $this->overrides->hasStoreWideAccount();
    }

    /**
     * Keeps the shape of separate stores read from the data: called before a
     * website of separate stores saves its values, so that websites given
     * the same Smaily account later stay separate stores.
     */
    public function remember(): void
    {
        if (!$this->isMarked()) {
            $this->configWriter->save(self::XML_PATH_SEPARATE_STORES, '1');
        }
    }

    /**
     * One store to separate stores: every website keeps what it reads now,
     * as its own values — each store-wide value of the Smaily account, the
     * Contacts settings and the automations it does not hold is copied to
     * it — and the store-wide values go, so that a website added later
     * starts as a store of its own with nothing set. A website on another
     * Smaily account than the store-wide one keeps only its own workflows
     * (CON-190). Separate stores already: nothing changes.
     */
    public function toSeparateStores(): void
    {
        if ($this->isSeparateStores()) {
            $this->configWriter->save(self::XML_PATH_SEPARATE_STORES, '1');

            return;
        }
        $websiteIds = $this->websiteIds();
        if (count($websiteIds) < 2) {
            return;
        }
        $otherAccount = [];
        foreach ($websiteIds as $websiteId) {
            $otherAccount[$websiteId] = $this->overrides->usesOtherAccount($websiteId);
        }
        foreach ($websiteIds as $websiteId) {
            foreach (self::SECTIONS as $section) {
                $this->overrides->copyStoreWideToWebsite($section, $websiteId, !$otherAccount[$websiteId]);
            }
        }
        foreach (self::SECTIONS as $section) {
            $this->overrides->removeStoreWideValues($section);
        }
        $this->configWriter->save(self::XML_PATH_SEPARATE_STORES, '1');
    }

    /**
     * Separate stores to one store: the values of $websiteId (the website
     * the merchant is looking at; the default website when none) become the
     * values for all websites, and every website's own values of the
     * Smaily account (with its store views' per-language accounts), the
     * Contacts settings and the automations are removed. One store already:
     * nothing changes.
     */
    public function toOneStore(?int $websiteId = null): void
    {
        if (!$this->isSeparateStores()) {
            $this->configWriter->delete(self::XML_PATH_SEPARATE_STORES);

            return;
        }
        $websiteIds = $this->websiteIds();
        if ($websiteId === null || !in_array($websiteId, $websiteIds, true)) {
            $websiteId = $this->defaultWebsiteId() ?? $websiteIds[0];
        }
        foreach (self::SECTIONS as $section) {
            $this->overrides->promoteToStoreWide($section, $websiteId);
        }
        $this->eventManager->dispatch(self::EVENT_JOINED, [
            'website_ids' => $websiteIds,
            'website_id' => $websiteId,
        ]);
        foreach ($websiteIds as $id) {
            foreach (self::SECTIONS as $section) {
                $this->overrides->removeOwnValues($section, $id, true);
            }
        }
        $this->configWriter->delete(self::XML_PATH_SEPARATE_STORES);
    }

    /**
     * The automations of a store whose websites hold separate Smaily
     * accounts and no store-wide one (CON-192; a 2.8.x upgrade or a store
     * set up on an earlier release candidate): it reads as separate
     * stores, so each website gets the store-wide automations it started
     * with as its own — config rows and mapping rows at its website scope
     * (WebsiteOverrides::copyStoreWideToWebsite()) — and sends on exactly
     * as before. The store-wide values stay. Run by the 2.8.x migration
     * and by a data patch for stores already upgraded; a second run finds
     * every value held already. Returns whether the store has that shape.
     */
    public function copyAutomationsToSeparateAccounts(): bool
    {
        if (!$this->overrides->holdsSeparateAccounts()) {
            return false;
        }
        foreach ($this->websiteIds() as $websiteId) {
            $this->overrides->copyStoreWideToWebsite(WebsiteOverrides::AUTOMATIONS, $websiteId);
        }

        return true;
    }

    /**
     * Read from the table, not the configuration cache: a save in this
     * request may just have written it.
     */
    private function isMarked(): bool
    {
        $connection = $this->resource->getConnection();

        return (string)$connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('core_config_data'), ['value'])
                ->where('scope = ?', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
                ->where('scope_id = ?', 0)
                ->where('path = ?', self::XML_PATH_SEPARATE_STORES)
        ) === '1';
    }

    /**
     * @return int[]
     */
    private function websiteIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $ids[] = (int)$website->getId();
        }
        sort($ids);

        return $ids;
    }

    private function defaultWebsiteId(): ?int
    {
        try {
            $id = (int)($this->storeManager->getDefaultStoreView()?->getWebsiteId() ?? 0);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $id > 0 ? $id : null;
    }
}
