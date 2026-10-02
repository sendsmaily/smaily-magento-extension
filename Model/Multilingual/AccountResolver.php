<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Multilingual;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;

/**
 * Maps Smaily account keys onto Magento store views, scoped to one website.
 *
 * The Woo-aligned surfaces (admin panels, mapping rows) address per-language
 * Smaily accounts by an account key (the language code, or 'default'); in
 * Magento a per-language account is a store-view scoped credential set, so
 * an account key resolves to the store views whose locale matches that
 * language. The binding unit is website x language (RFC_MULTI_WEBSITE.md
 * §2): two websites that both have an 'en' store view are distinct account
 * keys, never resolved across each other. Used both by the admin save path
 * (writing mode-A credentials) and by the automation dispatcher (posting a
 * mapping row's workflow through the account the row names).
 */
class AccountResolver
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly LanguageResolver $languageResolver
    ) {
    }

    /**
     * Store view IDs, within the given website, whose language matches the
     * account key.
     *
     * @return int[]
     */
    public function storeIdsForAccountKey(string $accountKey, int $websiteId): array
    {
        $website = $this->website($websiteId);
        if ($website === null) {
            return [];
        }

        $storeIds = [];
        foreach ($website->getStoreIds() as $storeId) {
            if ($this->languageResolver->forStore((int)$storeId) === $accountKey) {
                $storeIds[] = (int)$storeId;
            }
        }

        return $storeIds;
    }

    /**
     * Every store view of the given website with its current language
     * ('' when its locale gives none).
     *
     * @return array<int, string> store view id => language
     */
    public function storeLanguages(int $websiteId): array
    {
        $website = $this->website($websiteId);
        if ($website === null) {
            return [];
        }

        $languages = [];
        foreach ($website->getStoreIds() as $storeId) {
            $languages[(int)$storeId] = $this->languageResolver->forStore((int)$storeId);
        }

        return $languages;
    }

    /**
     * A representative store view, within the given website, for credential
     * resolution ('default' = default scope).
     */
    public function storeIdForAccountKey(string $accountKey, int $websiteId): ?int
    {
        if ($accountKey === '' || $accountKey === 'default') {
            return null;
        }
        $storeIds = $this->storeIdsForAccountKey($accountKey, $websiteId);

        return $storeIds[0] ?? null;
    }

    /**
     * Distinct storefront languages within the given website, its default
     * store's language first.
     *
     * @return string[]
     */
    public function detectedLanguages(int $websiteId): array
    {
        $website = $this->website($websiteId);
        if ($website === null) {
            return [];
        }

        $languages = [];
        $defaultStore = $website->getDefaultStore();
        if ($defaultStore !== null) {
            $default = $this->languageResolver->forStore((int)$defaultStore->getId());
            if ($default !== '') {
                $languages[] = $default;
            }
        }
        foreach ($website->getStoreIds() as $storeId) {
            $language = $this->languageResolver->forStore((int)$storeId);
            if ($language !== '' && !in_array($language, $languages, true)) {
                $languages[] = $language;
            }
        }

        return $languages;
    }

    /**
     * Representative store view per detected language on the given website:
     * language => store view id, or null when the language has no resolvable
     * store view. The shared walk behind every per-language-account surface
     * (admin credential blocks, workflow listing, consent reconcile).
     *
     * @return array<string, int|null>
     */
    public function languageStoreIds(int $websiteId): array
    {
        $map = [];
        foreach ($this->detectedLanguages($websiteId) as $language) {
            $map[$language] = $this->storeIdForAccountKey($language, $websiteId);
        }

        return $map;
    }

    /**
     * The website model behind an id, or null when the id no longer resolves
     * (a stale id from an event payload after a website was deleted must
     * resolve to "no stores", not throw).
     */
    private function website(int $websiteId): ?Website
    {
        try {
            $website = $this->storeManager->getWebsite($websiteId);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $website instanceof Website ? $website : null;
    }
}
