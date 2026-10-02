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
use Smaily\Connect\Model\Adminhtml\SetupNotice;

/**
 * Whether the browse tracker has a consent source the server can see.
 *
 * The tracker's consent comes from the store's own JavaScript override or,
 * under Magento cookie restriction mode, from Magento's cookie notice;
 * without either there is no consent and nothing is tracked. Only the
 * restriction mode is visible server-side, so the admin recommends a
 * consent source whenever a store view has it off (as the WooCommerce
 * plugin's needs_consent_api_notice does for the WP Consent API).
 */
class ConsentSource
{
    /** User Guide section on connecting a consent tool. */
    public const GUIDE_URL = SetupNotice::URL . '#connecting-your-cookie-consent-tool';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * True when Magento's cookie restriction mode is on in every store view.
     */
    public function isCookieRestrictionOnEverywhere(): bool
    {
        foreach ($this->storeManager->getStores() as $store) {
            if (!$this->scopeConfig->isSetFlag(
                CookieHelper::XML_PATH_COOKIE_RESTRICTION,
                ScopeInterface::SCOPE_STORE,
                $store->getId()
            )) {
                return false;
            }
        }

        return true;
    }
}
