<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Smaily\Connect\Model\Config;

/**
 * Whether the current request is a customer's own, logged in on the store's
 * own pages with marketing consent — the Campaign Intelligence customer
 * scope's group (c) as the store can see it on a later save of the account
 * (contract v1.23.0 §4; CON-266). A consenting login sends the customer
 * (Observer\Engine\CustomerLogin, CON-262); a save the same shopper makes
 * while logged in with consent keeps the engine's row current.
 *
 * The consent rule is the login's: MarketingConsent (the shopper's cookie
 * notice on this website), not at a store view with a Storefront URL saved
 * (a separate storefront's own banner decides there, PRO-3918). The
 * consent is a cookie of the shopper's browser, so it is read only on
 * Magento's own storefront pages, and only when the logged-in customer is
 * the customer saved: an admin, an API call, an import or a cron job sees
 * no shopper's consent.
 *
 * The customer session is a proxy (etc/di.xml): it is started only on a
 * storefront request.
 */
class ConsentingShopper
{
    public function __construct(
        private readonly State $appState,
        private readonly CustomerSession $customerSession,
        private readonly MarketingConsent $marketingConsent,
        private readonly Config $config
    ) {
    }

    public function isLoggedInWithConsent(int $customerId): bool
    {
        if ($customerId <= 0 || !$this->isStorefront() || $this->config->hasStorefrontUrl()) {
            return false;
        }
        if (!$this->customerSession->isLoggedIn()
            || (int)$this->customerSession->getCustomerId() !== $customerId
        ) {
            return false;
        }

        return $this->marketingConsent->isGiven();
    }

    private function isStorefront(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_FRONTEND;
        } catch (LocalizedException) {
            return false;
        }
    }
}
