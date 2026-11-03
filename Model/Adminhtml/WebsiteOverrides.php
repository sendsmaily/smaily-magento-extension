<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Automation\Mapping;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\MultilingualMode;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;
use Smaily\Connect\Model\SubdomainNormalizer;

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
            Config::XML_PATH_WELCOME_COUPON_ENABLED,
            Config::XML_PATH_WELCOME_COUPON_RULE,
            Config::XML_PATH_WELCOME_COUPON_DAYS,
            Config::XML_PATH_FIRST_ORDER_COUPON_ENABLED,
            Config::XML_PATH_FIRST_ORDER_COUPON_RULE,
            Config::XML_PATH_FIRST_ORDER_COUPON_DAYS,
            Config::XML_PATH_ABANDONED_COUPON_ENABLED,
            Config::XML_PATH_ABANDONED_COUPON_RULE,
            Config::XML_PATH_ABANDONED_COUPON_DAYS,
        ],
    ];

    /** The automations' workflows in system config. */
    private const WORKFLOW_PATHS = [
        Config::XML_PATH_WELCOME_WORKFLOW,
        Config::XML_PATH_FIRST_ORDER_WORKFLOW,
        Config::XML_PATH_ABANDONED_WORKFLOW,
    ];

    private const STORE_VIEW_CREDENTIALS = [
        Config::XML_PATH_SUBDOMAIN,
        Config::XML_PATH_USERNAME,
        Config::XML_PATH_PASSWORD,
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly WriterInterface $configWriter,
        private readonly StoreManagerInterface $storeManager,
        private readonly SubdomainNormalizer $normalizer
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
     * The website's own value of one path of a section, or null when the
     * website follows the store-wide value.
     */
    public function ownValue(string $section, int $websiteId, string $path): ?string
    {
        return $this->ownValues($section)[$websiteId][$path] ?? null;
    }

    /**
     * Whether a website of a 2+-website install uses another Smaily account
     * than the store-wide one (CON-190): the account it sends with — its own
     * subdomain and API username where it holds them, else the store-wide
     * ones — is not the store-wide account. The account's identity decides,
     * not the scope its login is saved at: a website that holds the
     * store-wide account's login of its own (a 2.8.x upgrade often saved it
     * on every website) uses the same account. Its automations are then
     * always its own, because the store-wide workflows were picked from
     * another account.
     *
     * False where no store-wide account is saved (only website logins, a
     * 2.8.x shape): which account the default-scope workflows were picked
     * from is not known, so they stay in use as before. Never on a
     * single-website install, where the store and its website are one.
     */
    public function usesOtherAccount(int $websiteId): bool
    {
        if (count($this->websiteIds()) < 2) {
            return false;
        }
        $own = $this->ownValues(self::CONNECTION)[$websiteId] ?? null;
        $storeWide = $this->storeWideAccount();
        if ($own === null || $storeWide === null) {
            return false;
        }
        $subdomain = isset($own[Config::XML_PATH_SUBDOMAIN])
            ? $this->normalizer->normalize($own[Config::XML_PATH_SUBDOMAIN])
            : $storeWide[0];
        $username = trim($own[Config::XML_PATH_USERNAME] ?? $storeWide[1]);

        return $subdomain !== $storeWide[0] || $username !== $storeWide[1];
    }

    /**
     * The store-wide Smaily account's identity — its normalized subdomain
     * and API username at the default scope — or null when either is empty.
     *
     * @return array{0: string, 1: string}|null
     */
    private function storeWideAccount(): ?array
    {
        $connection = $this->resource->getConnection();
        $values = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName('core_config_data'), ['path', 'value'])
                ->where('scope = ?', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
                ->where('scope_id = ?', 0)
                ->where('path IN (?)', [Config::XML_PATH_SUBDOMAIN, Config::XML_PATH_USERNAME])
        );
        $subdomain = $this->normalizer->normalize((string)($values[Config::XML_PATH_SUBDOMAIN] ?? ''));
        $username = trim((string)($values[Config::XML_PATH_USERNAME] ?? ''));

        return $subdomain === '' || $username === '' ? null : [$subdomain, $username];
    }

    /**
     * Whether the websites of a 2+-website install hold separate Smaily
     * accounts (CON-279, CON-192): no store-wide Smaily account is saved,
     * and at least two websites hold different accounts of their own (each
     * one's own subdomain and API username). Then each website sends with
     * its own account, as separate stores do. Websites that all hold the
     * same account of their own (a 2.8.x upgrade often saved it on every
     * website) are one store, and so is a store where one website holds an
     * account and the others none (a one-website store in per-language
     * accounts mode that gains a second website).
     */
    public function holdsSeparateAccounts(): bool
    {
        $websiteIds = $this->websiteIds();
        if (count($websiteIds) < 2 || $this->storeWideAccount() !== null) {
            return false;
        }
        $own = $this->ownValues(self::CONNECTION);
        $accounts = [];
        foreach ($websiteIds as $websiteId) {
            $subdomain = $this->normalizer->normalize((string)($own[$websiteId][Config::XML_PATH_SUBDOMAIN] ?? ''));
            $username = trim((string)($own[$websiteId][Config::XML_PATH_USERNAME] ?? ''));
            if ($subdomain !== '' || $username !== '') {
                $accounts[$subdomain . "\n" . $username] = true;
            }
        }

        return count($accounts) > 1;
    }

    /**
     * Whether a store-wide Smaily account (subdomain and API username at the
     * default scope) is saved.
     */
    public function hasStoreWideAccount(): bool
    {
        return $this->storeWideAccount() !== null;
    }

    /**
     * Gives a website, as its own values, every store-wide value of the
     * section it does not hold yet (CON-279): each default-scope row is
     * copied to its website scope as stored (the password stays
     * encrypted), and for the automations each store-wide mapping row
     * (website 0) it has no row of its own for. What the website reads does
     * not change.
     *
     * With $storeWideWorkflows false (a website on another Smaily account
     * than the store-wide one, CON-190, which never started the store-wide
     * workflows) a workflow it does not hold is saved as none, and no
     * store-wide mapping row is copied.
     */
    public function copyStoreWideToWebsite(string $section, int $websiteId, bool $storeWideWorkflows = true): void
    {
        $own = $this->ownValues($section)[$websiteId] ?? [];
        $storeWide = $this->storeWideValues($section);
        $noWorkflow = $section === self::AUTOMATIONS && !$storeWideWorkflows ? self::WORKFLOW_PATHS : [];
        foreach (self::PATHS[$section] as $path) {
            if (array_key_exists($path, $own)) {
                continue;
            }
            if (in_array($path, $noWorkflow, true)) {
                $this->configWriter->save($path, '0', ScopeInterface::SCOPE_WEBSITES, $websiteId);
            } elseif (array_key_exists($path, $storeWide)) {
                $this->configWriter->save($path, $storeWide[$path], ScopeInterface::SCOPE_WEBSITES, $websiteId);
            }
        }
        if ($section === self::AUTOMATIONS && $storeWideWorkflows) {
            $this->copyMappingRows(0, $websiteId);
        }
    }

    /**
     * Makes a website's values of the section the store-wide ones (CON-279,
     * separate stores back to one store): each of its own rows is written
     * at the default scope as stored, and for the automations its own
     * mapping rows join the store-wide rows (website 0), its own winning.
     * The store-wide values never take per-language Smaily accounts: mode A
     * becomes Single language there, and the rows of the per-language
     * accounts stay the website's. The website's own values stay until
     * removeOwnValues().
     */
    public function promoteToStoreWide(string $section, int $websiteId): void
    {
        foreach ($this->ownValues($section)[$websiteId] ?? [] as $path => $value) {
            if ($path === 'mappings') {
                continue;
            }
            if ($path === Config::XML_PATH_MULTILINGUAL_MODE && $value === MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS) {
                $value = MultilingualMode::MODE_SINGLE;
            }
            $this->configWriter->save($path, $value);
        }
        if ($section === self::AUTOMATIONS) {
            $this->copyMappingRows($websiteId, 0, true);
        }
    }

    /**
     * Removes the store-wide values of the section (CON-279, one store to
     * separate stores, once every website holds them as its own): its
     * default-scope rows, and for the automations the store-wide mapping
     * rows (website 0), the transactional ones apart.
     */
    public function removeStoreWideValues(string $section): void
    {
        foreach (self::PATHS[$section] as $path) {
            $this->configWriter->delete($path);
        }
        if ($section === self::AUTOMATIONS) {
            $this->resource->getConnection()->delete(
                $this->resource->getTableName(MappingResource::TABLE_NAME),
                [
                    'website_id = ?' => 0,
                    'trigger_type IN (?)' => Trigger::ALL,
                    'account_key <> ?' => Mapping::ACCOUNT_TRANSACTIONAL,
                ]
            );
        }
    }

    /**
     * The section's default-scope rows, path => value as stored.
     *
     * @return array<string, string>
     */
    private function storeWideValues(string $section): array
    {
        $connection = $this->resource->getConnection();
        $values = [];
        foreach ($connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName('core_config_data'), ['path', 'value'])
                ->where('scope = ?', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
                ->where('scope_id = ?', 0)
                ->where('path IN (?)', self::PATHS[$section])
        ) as $path => $value) {
            $values[(string)$path] = (string)$value;
        }

        return $values;
    }

    /**
     * Copies the non-transactional mapping rows of one website id to
     * another. A row the target holds for the same trigger, language and
     * account stays, unless $replace; a copied row is a trigger's default
     * fallback only where the target holds no fallback of that kind (the
     * one account's rows, or the per-language accounts' rows) for the
     * trigger, so the Router still finds one fallback.
     */
    private function copyMappingRows(int $fromWebsiteId, int $toWebsiteId, bool $replace = false): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(MappingResource::TABLE_NAME);
        $rows = static fn (int $websiteId, bool $oneAccountOnly): array => $connection->fetchAll(
            $connection->select()
                ->from($table, ['trigger_type', 'language', 'account_key', 'workflow_id', 'is_default_fallback'])
                ->where('website_id = ?', $websiteId)
                ->where('trigger_type IN (?)', Trigger::ALL)
                ->where(
                    $oneAccountOnly ? 'account_key = ?' : 'account_key <> ?',
                    $oneAccountOnly ? Mapping::ACCOUNT_DEFAULT : Mapping::ACCOUNT_TRANSACTIONAL
                )
        );
        $key = static fn (array $row): string => $row['trigger_type'] . "\n" . $row['language'] . "\n"
            . $row['account_key'];
        $kind = static fn (array $row): string => $row['trigger_type'] . "\n"
            . ($row['account_key'] === Mapping::ACCOUNT_DEFAULT ? 'one' : 'per-language');

        // The store-wide rows never name a per-language account.
        $source = $rows($fromWebsiteId, $toWebsiteId === 0);
        if ($source === []) {
            return;
        }
        $held = [];
        $fallbacks = [];
        foreach ($rows($toWebsiteId, false) as $row) {
            $held[$key($row)] = true;
            if ((int)$row['is_default_fallback'] === 1) {
                $fallbacks[$kind($row)] = true;
            }
        }
        if ($replace) {
            // The copied rows win: a fallback among them takes over its kind.
            foreach ($source as $row) {
                if ((int)$row['is_default_fallback'] !== 1) {
                    continue;
                }
                $where = ['website_id = ?' => $toWebsiteId, 'trigger_type = ?' => $row['trigger_type']];
                if ($row['account_key'] === Mapping::ACCOUNT_DEFAULT) {
                    $where['account_key = ?'] = Mapping::ACCOUNT_DEFAULT;
                } else {
                    $where['account_key NOT IN (?)'] = [Mapping::ACCOUNT_DEFAULT, Mapping::ACCOUNT_TRANSACTIONAL];
                }
                $connection->update($table, ['is_default_fallback' => 0], $where);
                unset($fallbacks[$kind($row)]);
            }
        }
        foreach ($source as $row) {
            if (isset($held[$key($row)]) && !$replace) {
                continue;
            }
            $fallback = (int)$row['is_default_fallback'] === 1 && !isset($fallbacks[$kind($row)]);
            if ($fallback) {
                $fallbacks[$kind($row)] = true;
            }
            $connection->insertOnDuplicate(
                $table,
                [
                    'website_id' => $toWebsiteId,
                    'trigger_type' => $row['trigger_type'],
                    'language' => $row['language'],
                    'account_key' => $row['account_key'],
                    'workflow_id' => (int)$row['workflow_id'],
                    'is_default_fallback' => $fallback ? 1 : 0,
                ],
                ['workflow_id', 'is_default_fallback']
            );
        }
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
