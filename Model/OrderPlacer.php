<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;

/**
 * Who places the order saved in this request: the one rule for an order an
 * admin places, used by the attribution stamp (PRO-3930) and by the order
 * origin flags (PRO-3949).
 */
class OrderPlacer
{
    /**
     * Magento's "Login as Customer" (optional: no composer or module.xml
     * dependency), resolved by name only while the module is enabled. Its
     * API answers the admin id held in the customer session, the same check
     * Magento_LoginAsCustomerSales marks such an order with. A string, not
     * ::class: the interface may be absent.
     */
    private const LOGIN_AS_CUSTOMER_MODULE = 'Magento_LoginAsCustomer';
    private const LOGGED_AS_CUSTOMER_ADMIN_ID = 'Magento\\LoginAsCustomerApi\\Api\\GetLoggedAsCustomerAdminIdInterface';

    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly ObjectManagerInterface $objectManager,
        private readonly State $appState
    ) {
    }

    /**
     * Whether an admin places the order: in the admin's order screen (any
     * order saved in an admin request, PRO-3930), or on the storefront while
     * logged in as the customer ("Login as Customer", PRO-3925).
     */
    public function isAdmin(): bool
    {
        try {
            $area = $this->appState->getAreaCode();
        } catch (LocalizedException) {
            $area = null; // No area set: not an admin request.
        }
        if ($area === Area::AREA_ADMINHTML) {
            return true;
        }
        if (!$this->moduleManager->isEnabled(self::LOGIN_AS_CUSTOMER_MODULE)) {
            return false;
        }

        return (int)$this->objectManager->get(self::LOGGED_AS_CUSTOMER_ADMIN_ID)->execute() > 0;
    }
}
