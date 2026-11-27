<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;
use Smaily\Connect\Model\Adminhtml\WebsiteContacts;
use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;
use Smaily\Connect\Model\StoreLayout;

/**
 * A store with several websites run as one store on one Smaily account
 * shares its Smaily account, Contacts settings and automations across its
 * websites (CON-470), so the upgrade leaves no website's own value of them
 * in effect unseen:
 *
 * 1. Settings that one website alone holds (an initial setup on 3.0.0-rc1
 *    to rc12 saved them at the chosen website's scope, and every other
 *    website had no Smaily connection) become the settings for all websites
 *    (StoreLayout::shareSingleWebsiteSettings()).
 * 2. Where a Smaily account is saved for all websites, the values for all
 *    websites win: every website's own Smaily account and automations go
 *    where the default scope holds those sections, and its own Contacts
 *    settings go where they differ in no consent question
 *    (WebsiteContacts). Contacts settings that do differ stay until the
 *    merchant confirms on Settings > Contacts.
 *
 * A website's storefront address stays its own (WebsiteOverrides::PER_WEBSITE).
 * Only a store whose setup is finished and whose websites and store views
 * use one Smaily account, not separate stores and without per-language
 * Smaily accounts (StoreLayout::isSetUpAsOneStore()); any other store is
 * left as it is. Config and mapping rows only, no schema change. Each log
 * line names the section, the website and the paths, never a value.
 */
class ShareSingleWebsiteSettings implements DataPatchInterface
{
    private const MOVED = 'one website\'s settings now apply to every website';
    private const REMOVED = 'a website\'s own settings gave way to the ones for all websites';

    public function __construct(
        private readonly StoreLayout $storeLayout,
        private readonly WebsiteOverrides $overrides,
        private readonly WebsiteContacts $websiteContacts,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [
            MigrateLegacyConfig::class,
            ConfirmContactsOfConnectedWebsites::class,
            CopyAutomationsToSeparateStores::class,
        ];
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
        foreach ($this->storeLayout->shareSingleWebsiteSettings() as $section => $moved) {
            $this->log(self::MOVED, $section, $moved['website'], $moved['paths']);
        }
        if (!$this->storeLayout->isSetUpAsOneStore() || !$this->overrides->hasStoreWideAccount()) {
            return $this;
        }
        foreach ([WebsiteOverrides::CONNECTION, WebsiteOverrides::AUTOMATIONS] as $section) {
            if (!$this->overrides->hasStoreWideValues($section)) {
                continue;
            }
            foreach ($this->overrides->sharedOwnValues($section) as $websiteId => $values) {
                $this->overrides->removeOwnValues($section, $websiteId, false, WebsiteOverrides::PER_WEBSITE);
                $this->log(self::REMOVED, $section, $websiteId, array_keys($values));
            }
        }
        if ($this->overrides->hasStoreWideValues(WebsiteOverrides::SUBSCRIBERS)) {
            $own = $this->overrides->ownValues(WebsiteOverrides::SUBSCRIBERS);
            foreach ($this->websiteContacts->identical() as $websiteId) {
                $this->overrides->removeOwnValues(WebsiteOverrides::SUBSCRIBERS, $websiteId);
                $this->log(
                    self::REMOVED,
                    WebsiteOverrides::SUBSCRIBERS,
                    $websiteId,
                    array_keys($own[$websiteId] ?? [])
                );
            }
        }

        return $this;
    }

    /**
     * @param string[] $paths
     */
    private function log(string $what, string $section, int $websiteId, array $paths): void
    {
        $this->logger->info('Smaily Connect upgrade: ' . $what, [
            'section' => $section,
            'website_id' => $websiteId,
            'paths' => StoreLayout::pathNames($paths),
        ]);
    }
}
