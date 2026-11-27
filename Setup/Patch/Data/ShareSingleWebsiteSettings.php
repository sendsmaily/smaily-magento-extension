<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Setup\Patch\Data;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;
use Smaily\Connect\Model\Adminhtml\WebsiteContacts;
use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\StoreLayout;

/**
 * A store with several websites run as one store on one Smaily account
 * shares its Smaily account, Contacts settings and automations across its
 * websites (CON-470), so the upgrade leaves no website's own value of them
 * in effect unseen where it can do so without losing anything:
 *
 * 1. Settings that one website alone holds (an initial setup on 3.0.0-rc1
 *    to rc12 saved them at the chosen website's scope, and every other
 *    website had no Smaily connection) become the settings for all websites
 *    (StoreLayout::shareSingleWebsiteSettings()).
 * 2. Where a Smaily account is saved for all websites:
 *    - The Smaily account: a website's own account values go only where
 *      its API password, decrypted, is the one for all websites; where no
 *      password is saved for all websites, the one every website that
 *      holds a password agrees on (decrypted) moves there first, as stored.
 *      A website whose password differs keeps its whole account.
 *    - The automations: WebsiteOverrides::shareAutomations() — path by path
 *      and automation by automation, the values for all websites win, and a
 *      value they lack moves there when the websites agree on it; a website
 *      holding a value the websites disagree on keeps its automations.
 *    - The Contacts settings: a website's own go where they differ in no
 *      consent question (WebsiteContacts); the others stay until the
 *      merchant confirms on Settings > Contacts.
 *
 * A website's storefront address stays its own (WebsiteOverrides::PER_WEBSITE).
 * Only a store whose setup is finished and whose websites and store views
 * use one Smaily account, not separate stores and without per-language
 * Smaily accounts (StoreLayout::isSetUpAsOneStore()); any other store is
 * left as it is. Config and mapping rows only, no schema change. Each log
 * line names the section, the website and the paths, never a value: a
 * password is compared decrypted in memory only.
 */
class ShareSingleWebsiteSettings implements DataPatchInterface
{
    private const MOVED = 'one website\'s settings now apply to every website';
    private const REMOVED = 'a website\'s own settings gave way to the ones for all websites';
    private const KEPT = 'a website keeps its own settings';

    public function __construct(
        private readonly StoreLayout $storeLayout,
        private readonly WebsiteOverrides $overrides,
        private readonly WebsiteContacts $websiteContacts,
        private readonly EncryptorInterface $encryptor,
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
        $this->shareConnection();

        [$removed, $kept] = $this->overrides->shareAutomations();
        foreach ($removed as $websiteId => $paths) {
            $this->log(self::REMOVED, WebsiteOverrides::AUTOMATIONS, $websiteId, $paths);
        }
        foreach ($kept as $websiteId) {
            $this->log(self::KEPT, WebsiteOverrides::AUTOMATIONS, $websiteId, [], 'websites disagree');
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
     * The websites' own Smaily account values give way to the ones for all
     * websites only where nothing is lost: the website's API password,
     * decrypted, is the one for all websites (its own, else that one).
     */
    private function shareConnection(): void
    {
        $own = $this->overrides->sharedOwnValues(WebsiteOverrides::CONNECTION);
        if ($own === []) {
            return;
        }
        $stored = $this->overrides->storeWideValues(WebsiteOverrides::CONNECTION)[Config::XML_PATH_PASSWORD] ?? '';
        if ($stored === '') {
            $stored = $this->agreedPassword($own);
            if ($stored !== '') {
                $this->overrides->promotePath(Config::XML_PATH_PASSWORD, $stored);
                $this->log(self::MOVED, WebsiteOverrides::CONNECTION, 0, [Config::XML_PATH_PASSWORD]);
            }
        }
        $storeWide = $this->decrypt($stored);
        foreach ($own as $websiteId => $values) {
            $password = $this->decrypt($values[Config::XML_PATH_PASSWORD] ?? $stored);
            if ($storeWide === null || $password === null || !hash_equals($storeWide, $password)) {
                $this->log(self::KEPT, WebsiteOverrides::CONNECTION, $websiteId, [], 'password differs');
                continue;
            }
            $this->overrides->removeOwnValues(
                WebsiteOverrides::CONNECTION,
                $websiteId,
                false,
                WebsiteOverrides::PER_WEBSITE
            );
            $this->log(self::REMOVED, WebsiteOverrides::CONNECTION, $websiteId, array_keys($values));
        }
    }

    /**
     * The stored password every website that holds one agrees on, decrypted;
     * '' when none holds one or they disagree.
     *
     * @param array<int, array<string, string>> $own
     */
    private function agreedPassword(array $own): string
    {
        $agreed = null;
        $stored = '';
        foreach ($own as $values) {
            if (!isset($values[Config::XML_PATH_PASSWORD])) {
                continue;
            }
            $password = $this->decrypt($values[Config::XML_PATH_PASSWORD]);
            if ($password === null || ($agreed !== null && !hash_equals($agreed, $password))) {
                return '';
            }
            $agreed = $password;
            $stored = $values[Config::XML_PATH_PASSWORD];
        }

        return $stored;
    }

    /**
     * A stored password decrypted, in memory only; null when there is none
     * or it cannot be read.
     */
    private function decrypt(string $stored): ?string
    {
        if ($stored === '') {
            return null;
        }
        try {
            $password = $this->encryptor->decrypt($stored);
        } catch (\Throwable) {
            return null;
        }

        return $password === '' ? null : $password;
    }

    /**
     * @param string[] $paths
     */
    private function log(string $what, string $section, int $websiteId, array $paths, string $why = ''): void
    {
        $context = [
            'section' => $section,
            'website_id' => $websiteId,
            'paths' => StoreLayout::pathNames($paths),
        ];
        if ($why !== '') {
            $context['reason'] = $why;
        }
        $this->logger->info('Smaily Connect upgrade: ' . $what, $context);
    }
}
