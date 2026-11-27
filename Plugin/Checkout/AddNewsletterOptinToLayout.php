<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Plugin\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessor;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Config;

/**
 * Injects the newsletter opt-in checkbox into the Luma/Knockout checkout
 * payment step. Hyvä Checkout has its own integration surface and is
 * handled by a separate compatibility package.
 *
 * The checkbox shows on a connected website while its contact sync is active
 * (PRO-4036) or its welcome automation is on (CON-477): a tick is the
 * shopper's own subscription, and the welcome follows it with contact sync
 * off too.
 */
class AddNewsletterOptinToLayout
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param array<string, mixed> $jsLayout
     * @return array<string, mixed>
     */
    public function afterProcess(LayoutProcessor $subject, array $jsLayout): array
    {
        $websiteId = (int)$this->storeManager->getWebsite()->getId();
        $storeId = (int)$this->storeManager->getStore()->getId();
        if (!$this->config->isCheckoutOptinEnabled($websiteId)
            || !$this->config->isConnected($storeId ?: null)
            || (!$this->config->isContactSyncActive($websiteId) && !$this->config->isWelcomeEnabled($websiteId))
        ) {
            return $jsLayout;
        }

        // The afterMethods region renders once below the payment methods list
        // in Luma's payment.html; per-method regions like beforePlaceOrder
        // would render nothing at this level.
        $afterMethods = &$jsLayout['components']['checkout']['children']['steps']['children']
            ['billing-step']['children']['payment']['children']['afterMethods']['children'];
        if (!is_array($afterMethods)) {
            return $jsLayout;
        }

        $afterMethods['smaily-newsletter-optin'] = [
            'component' => 'Smaily_Connect/js/view/checkout/newsletter-optin',
            'displayArea' => 'afterMethods',
            'sortOrder' => 100,
        ];

        return $jsLayout;
    }
}
