<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\AbandonedCart;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Config;

/**
 * Tells the checkout email field whether to send a guest's typed email to
 * the cart (PRO-3693): only while the abandoned-cart automation is on for
 * the website, the one condition of GuestCartEmail the page can know. With
 * it off, the email-mixin sends nothing, instead of a request the endpoint
 * would refuse.
 */
class GuestCartEmailConfigProvider implements ConfigProviderInterface
{
    public const CONFIG_KEY = 'smailyGuestCartEmail';

    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return array<string, bool>
     */
    public function getConfig(): array
    {
        $websiteId = (int)$this->storeManager->getWebsite()->getId();

        return [self::CONFIG_KEY => $this->config->isAbandonedCartEnabled($websiteId)];
    }
}
