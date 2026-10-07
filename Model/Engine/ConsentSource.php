<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\UserGuide;

/**
 * Whether the browse tracker has a consent source the server can see.
 *
 * The tracker's consent comes from the store's own JavaScript override or,
 * under Magento cookie restriction mode, from Magento's cookie notice;
 * without either there is no consent and nothing is tracked. Only the
 * restriction mode is visible server-side, so the admin recommends a
 * consent source whenever a store view has it off (as the WooCommerce
 * plugin's needs_consent_api_notice does for the WP Consent API).
 * A store view with a Storefront URL saved sells on a separate storefront,
 * whose own consent banner decides (PRO-3918).
 */
class ConsentSource
{
    /** User Guide section on connecting a consent tool. */
    public const GUIDE_URL = UserGuide::URL . '#' . UserGuide::SECTION_CONSENT_TOOL;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    /**
     * True when Magento's cookie restriction mode is on in every store view.
     */
    public function isCookieRestrictionOnEverywhere(): bool
    {
        foreach ($this->storeManager->getStores() as $store) {
            if (!$this->isRestrictionOn((int)$store->getId())) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when a store view whose shoppers browse Magento's own pages has
     * Magento's cookie restriction mode off: its tracker has no consent
     * source the server can see. A store view with a Storefront URL saved
     * does not count — its separate storefront sends the browse events and
     * its own consent banner decides (PRO-3918).
     */
    public function isMissing(): bool
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            if (!$this->isRestrictionOn($storeId) && !$this->config->hasStorefrontUrl($storeId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether Magento's cookie restriction mode is on in this store view.
     */
    private function isRestrictionOn(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            CookieHelper::XML_PATH_COOKIE_RESTRICTION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
