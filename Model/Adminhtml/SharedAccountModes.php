<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * The websites that sync contacts to one Smaily account (one subdomain) with
 * different contact sync modes (CON-432, the rule of the four connectors).
 * Smaily keeps one subscription status per address, so the reconcile of a
 * Subscribers only website can mark subscribed a contact whom an All
 * customers website sent: the Contacts panel warns each website of such a
 * group, from the saved settings.
 *
 * A website's accounts are those of its store views, so a store view with a
 * Smaily account of its own puts the website on that account too. A website
 * whose contact sync is not active sends nobody and is left out.
 */
class SharedAccountModes
{
    public function __construct(
        private readonly Config $config,
        private readonly Mode $mode,
        private readonly StoreManagerInterface $storeManager,
        private readonly SubdomainNormalizer $normalizer
    ) {
    }

    /**
     * The websites, with their modes, on a Smaily account of the given
     * website (0 = the whole store: every account) where the websites on
     * that account use different modes; else none.
     *
     * @return list<array{website: string, mode: string}>
     */
    public function of(int $websiteId): array
    {
        $websites = $this->storeManager->getWebsites();
        if (count($websites) < 2) {
            return [];
        }

        $accounts = [];
        $members = [];
        foreach ($websites as $website) {
            if (!$website instanceof Website) {
                continue;
            }
            $id = (int)$website->getId();
            $accounts[$id] = $this->subdomains(array_map('intval', $website->getStoreIds()));
            if ($this->config->isContactSyncActive($id)) {
                $members[$id] = ['website' => (string)$website->getName(), 'mode' => $this->mode->mode($id)];
            }
        }

        $targets = $websiteId === 0 ? array_merge([], ...array_values($accounts)) : ($accounts[$websiteId] ?? []);
        $warned = [];
        foreach (array_unique($targets) as $subdomain) {
            $group = array_filter(
                $members,
                static fn (int $id): bool => in_array($subdomain, $accounts[$id], true),
                ARRAY_FILTER_USE_KEY
            );
            if (count(array_unique(array_column($group, 'mode'))) > 1) {
                $warned += $group;
            }
        }

        return array_values(array_intersect_key($members, $warned));
    }

    /**
     * The Smaily subdomains the store views use.
     *
     * @param int[] $storeIds
     * @return string[]
     */
    private function subdomains(array $storeIds): array
    {
        $subdomains = [];
        foreach ($storeIds as $storeId) {
            $subdomain = $this->normalizer->normalize($this->config->getSubdomain($storeId));
            if ($subdomain !== '') {
                $subdomains[] = $subdomain;
            }
        }

        return array_values(array_unique($subdomains));
    }
}
