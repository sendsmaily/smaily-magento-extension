<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

use Magento\Store\Model\StoreManagerInterface;

/**
 * The websites of an installation that have no Smaily connection while
 * another website has one (CON-470). A store whose setup gave only one
 * website the Smaily account dropped every other website's newsletter
 * signups without a trace: such a signup is now recorded in the Log as
 * skipped (SKIPPED_NOT_CONNECTED), and the Dashboard names these websites.
 * A website is connected when its Smaily account — its own, else the one
 * for all websites — has a subdomain, an API username and a password. A
 * single-website install, or one with no website connected, lists none: a
 * store not set up yet is not warned about.
 */
class WebsiteConnections
{
    /** The reason a contact sync of a website without a Smaily connection was not sent. */
    public const SKIPPED_NOT_CONNECTED = 'Skipped: the contact\'s website has no Smaily connection.'
        . ' Nothing was sent.';

    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Website id => name of each website without a Smaily connection, in
     * website order, when another website has one; else empty.
     *
     * @return array<int, string>
     */
    public function unconnectedWebsites(): array
    {
        $connected = false;
        $unconnected = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int)$website->getId();
            if ($this->isConnected($websiteId)) {
                $connected = true;
            } else {
                $unconnected[$websiteId] = (string)$website->getName();
            }
        }
        ksort($unconnected);

        return $connected ? $unconnected : [];
    }

    private function isConnected(int $websiteId): bool
    {
        return $this->config->getWebsiteSubdomain($websiteId) !== ''
            && $this->config->getWebsiteUsername($websiteId) !== ''
            && $this->config->hasWebsitePassword($websiteId);
    }
}
