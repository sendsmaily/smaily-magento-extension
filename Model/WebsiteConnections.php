<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;

/**
 * The websites of an installation that have no Smaily connection while
 * another website has one (CON-470). A store whose setup gave only one
 * website the Smaily account dropped every other website's newsletter
 * signups without a trace: such a signup is now recorded in the Log as
 * skipped (SKIPPED_NOT_CONNECTED), and the Dashboard of one store names
 * these websites. A website is connected when one of its store views has a
 * Smaily account — the store view's own, else its website's, else the one
 * for all websites — with a subdomain, an API username and a password (a
 * website without store views: its own, else the one for all websites). A
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
            if ($this->isConnected($website)) {
                $connected = true;
            } else {
                $unconnected[(int)$website->getId()] = (string)$website->getName();
            }
        }
        ksort($unconnected);

        return $connected ? $unconnected : [];
    }

    private function isConnected(WebsiteInterface $website): bool
    {
        $storeIds = $website instanceof Website ? array_map('intval', $website->getStoreIds()) : [];
        foreach ($storeIds as $storeId) {
            if ($this->config->getSubdomain($storeId) !== ''
                && $this->config->getUsername($storeId) !== ''
                && $this->config->hasPassword($storeId)
            ) {
                return true;
            }
        }
        $websiteId = (int)$website->getId();

        return $storeIds === []
            && $this->config->getWebsiteSubdomain($websiteId) !== ''
            && $this->config->getWebsiteUsername($websiteId) !== ''
            && $this->config->hasWebsitePassword($websiteId);
    }
}
