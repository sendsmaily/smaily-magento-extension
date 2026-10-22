<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Automation\Mapping;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;

/**
 * The values a website holds of its own for the settings the initial setup
 * saves for the whole store (CON-179). A website follows the store-wide
 * value (the default scope) of a setting unless it holds its own value: a
 * row at its website scope — from Settings, from the 2.8.x migration, or from
 * an initial setup run before CON-179, which saved per website. For the
 * automations a website's own workflow mapping rows count as well.
 *
 * The settings come in three sections, each kept or returned as a whole: the
 * Smaily account (Settings > Connection), the Contacts settings and the
 * automations. The internal mark that a website's Contacts settings were
 * saved (Config::XML_PATH_CONTACTS_CONFIRMED) is not an own value: the
 * migration writes it for every website that synced, and it is never
 * removed, so a website that synced keeps syncing.
 */
class WebsiteOverrides
{
    public const CONNECTION = 'connection';
    public const SUBSCRIBERS = 'subscribers';
    public const AUTOMATIONS = 'automations';

    public const PATHS = [
        self::CONNECTION => [
            Config::XML_PATH_SUBDOMAIN,
            Config::XML_PATH_USERNAME,
            Config::XML_PATH_PASSWORD,
            Config::XML_PATH_MULTILINGUAL_MODE,
            Config::XML_PATH_FALLBACK_LANGUAGE,
            Config::XML_PATH_STOREFRONT_URL,
        ],
        self::SUBSCRIBERS => [
            Config::XML_PATH_SYNC_ENABLED,
            Config::XML_PATH_SYNC_MODE,
            Config::XML_PATH_SYNC_FIELDS,
            Config::XML_PATH_INCLUDE_GUESTS,
            Config::XML_PATH_CHECKOUT_OPTIN_ENABLED,
            Config::XML_PATH_SUPPRESS_OPTIN_EMAILS,
        ],
        self::AUTOMATIONS => [
            Config::XML_PATH_WELCOME_ENABLED,
            Config::XML_PATH_WELCOME_WORKFLOW,
            Config::XML_PATH_FIRST_ORDER_ENABLED,
            Config::XML_PATH_FIRST_ORDER_WORKFLOW,
            Config::XML_PATH_ABANDONED_ENABLED,
            Config::XML_PATH_ABANDONED_WORKFLOW,
            Config::XML_PATH_ABANDONED_CUTOFF,
        ],
    ];

    private const STORE_VIEW_CREDENTIALS = [
        Config::XML_PATH_SUBDOMAIN,
        Config::XML_PATH_USERNAME,
        Config::XML_PATH_PASSWORD,
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly WriterInterface $configWriter,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The own values of every real website that holds any for the section,
     * in website order: website id => [path => value]; for the automations
     * the number of its own workflow mapping rows under 'mappings'.
     *
     * @return array<int, array<string, string>>
     */
    public function ownValues(string $section): array
    {
        $websiteIds = $this->websiteIds();
        if ($websiteIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $own = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('core_config_data'), ['scope_id', 'path', 'value'])
                ->where('scope = ?', ScopeInterface::SCOPE_WEBSITES)
                ->where('scope_id IN (?)', $websiteIds)
                ->where('path IN (?)', self::PATHS[$section])
        ) as $row) {
            $own[(int)$row['scope_id']][(string)$row['path']] = (string)$row['value'];
        }
        if ($section === self::AUTOMATIONS) {
            foreach ($connection->fetchPairs(
                $connection->select()
                    ->from($this->resource->getTableName(MappingResource::TABLE_NAME), ['website_id', 'COUNT(*)'])
                    ->where('website_id IN (?)', $websiteIds)
                    ->where('trigger_type IN (?)', Trigger::ALL)
                    ->where('account_key <> ?', Mapping::ACCOUNT_TRANSACTIONAL)
                    ->group('website_id')
            ) as $websiteId => $count) {
                $own[(int)$websiteId]['mappings'] = (string)$count;
            }
        }

        $ordered = [];
        foreach ($websiteIds as $websiteId) {
            if (isset($own[$websiteId])) {
                $ordered[$websiteId] = $own[$websiteId];
            }
        }

        return $ordered;
    }

    public function hasOwnValues(string $section, int $websiteId): bool
    {
        return isset($this->ownValues($section)[$websiteId]);
    }

    /**
     * Removes a website's own values of the section, so it follows the
     * store-wide values again. For the Smaily account, with
     * $storeViewAccounts, its store views' own accounts (multilingual mode
     * A, which it set for itself) go as well.
     */
    public function removeOwnValues(string $section, int $websiteId, bool $storeViewAccounts = false): void
    {
        foreach (self::PATHS[$section] as $path) {
            $this->configWriter->delete($path, ScopeInterface::SCOPE_WEBSITES, $websiteId);
        }
        if ($section === self::CONNECTION && $storeViewAccounts) {
            $website = $this->storeManager->getWebsite($websiteId);
            if ($website instanceof Website) {
                foreach ($website->getStoreIds() as $storeId) {
                    foreach (self::STORE_VIEW_CREDENTIALS as $path) {
                        $this->configWriter->delete($path, ScopeInterface::SCOPE_STORES, (int)$storeId);
                    }
                }
            }
        }
        if ($section === self::AUTOMATIONS) {
            $this->resource->getConnection()->delete(
                $this->resource->getTableName(MappingResource::TABLE_NAME),
                [
                    'website_id = ?' => $websiteId,
                    'trigger_type IN (?)' => Trigger::ALL,
                    'account_key <> ?' => Mapping::ACCOUNT_TRANSACTIONAL,
                ]
            );
        }
    }

    /**
     * @return int[]
     */
    private function websiteIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $ids[] = (int)$website->getId();
        }
        sort($ids);

        return $ids;
    }
}
