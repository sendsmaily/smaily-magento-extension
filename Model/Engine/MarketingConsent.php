<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Cookie\Helper\Cookie as CookieHelper;

/**
 * Whether the current shopper gave marketing consent that the server can
 * see — the WooCommerce plugin's MarketingConsent::given() (PRO-3849) in
 * Magento's shape. The storefront scripts' rule (`js/consent.js`, PRO-3664)
 * takes the store's own `consentOverride()` first, else Magento's cookie
 * notice under cookie restriction mode, else no consent. The override lives
 * only in the browser, so on the server consent is an explicit yes only:
 * cookie restriction mode is on in this store view and the shopper accepted
 * the cookie notice on this website (`user_allowed_save_cookie`, read by
 * Magento's cookie helper). Restriction mode off is no consent.
 *
 * A store-created `vs_` visitor token reaches the engine only when this
 * holds (contract §5: without marketing consent, send none; CON-181).
 */
class MarketingConsent
{
    public function __construct(
        private readonly CookieHelper $cookieHelper
    ) {
    }

    public function isGiven(): bool
    {
        return (bool)$this->cookieHelper->isCookieRestrictionModeEnabled()
            && !$this->cookieHelper->isUserNotAllowSaveCookie();
    }
}
