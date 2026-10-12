<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Migration\MigrationOutcome;

/**
 * Contact sync waits for a website's Contacts settings to be saved
 * (PRO-4036). A store already on a 3.0.0 release candidate synced the
 * contacts of every website with credentials saved, without that save, so
 * the update marks the Contacts settings saved for each such website — its
 * credentials as the website reads them, its own else the default scope's
 * — and it keeps syncing.
 *
 * Nothing else is marked: on a new install no website has credentials yet,
 * and an upgrade from 2.8.x in this setup run is MigrateLegacyConfig's,
 * which marks only the websites whose 2.8.x contact sync was on.
 */
class ConfirmContactsOfConnectedWebsites implements DataPatchInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly WriterInterface $configWriter,
        private readonly MigrationOutcome $migrationOutcome
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [MigrateLegacyConfig::class];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        if ($this->migrationOutcome->wasMigrated()) {
            return $this;
        }

        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int)$website->getId();
            if ($this->config->getWebsiteSubdomain($websiteId) !== ''
                && $this->config->getWebsiteUsername($websiteId) !== ''
                && $this->config->getWebsitePassword($websiteId) !== ''
            ) {
                $this->configWriter->save(
                    Config::XML_PATH_CONTACTS_CONFIRMED,
                    '1',
                    ScopeInterface::SCOPE_WEBSITES,
                    $websiteId
                );
            }
        }

        return $this;
    }
}
