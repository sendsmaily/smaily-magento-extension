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
 * an initial setup run before CON-179, which saved per website.
 *
 * The settings come in three sections, each kept or returned as a whole: the
 * Smaily account (Settings > Connection), the Contacts settings and the
 * automations. A fourth, the transactional emails (their account, switches
 * and workflows), has no "own" switch under one store — a website saves its
 * switches for itself and may use its own account
 * (TransactionalSettingsSaver) — but a change of the store's layout
 * (StoreLayout, CON-281) carries it as it carries the three. For the
 * automations and the transactional emails a website's own workflow
 * mapping rows of the section count as own values too. The internal mark
 * that a website's Contacts settings were saved
 * (Config::XML_PATH_CONTACTS_CONFIRMED) is not an own value: the migration
 * writes it for every website that synced, and it is never removed, so a
 * website that synced keeps syncing.
 */
class WebsiteOverrides
{
    public const CONNECTION = 'connection';
    public const SUBSCRIBERS = 'subscribers';
    public const AUTOMATIONS = 'automations';
    public const TRANSACTIONAL = 'transactional';

    /**
     * The paths every website keeps of its own also where the websites
     * share their settings (CON-470): a separate storefront has an address
     * per website.
     */
    public const PER_WEBSITE = [Config::XML_PATH_STOREFRONT_URL];

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
        self::TRANSACTIONAL => [
            Config::XML_PATH_TRANSACTIONAL_ENABLED,
            Config::XML_PATH_TRANSACTIONAL_SUBDOMAIN,
            Config::XML_PATH_TRANSACTIONAL_USERNAME,
            Config::XML_PATH_TRANSACTIONAL_PASSWORD,
            Config::XML_PATH_TRANSACTIONAL_WEBSITE_ACCOUNT,
            Config::XML_PATH_TRANSACTIONAL_PERSONAL_DATA,
            Config::XML_PATH_ORDER_CONFIRMATION_ENABLED,
            Config::XML_PATH_SHIPMENT_CONFIRMATION_ENABLED,
        ],
    ];

    /** The workflow mapping rows of the sections that have them. */
    private const MAPPING_ROWS = [
        self::AUTOMATIONS => [
            'trigger_type IN (?)' => Trigger::ALL,
            'account_key <> ?' => Mapping::ACCOUNT_TRANSACTIONAL,
        ],
        self::TRANSACTIONAL => [
            'trigger_type IN (?)' => Trigger::TRANSACTIONAL,
            'account_key = ?' => Mapping::ACCOUNT_TRANSACTIONAL,
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
     * and the transactional emails the number of its own workflow mapping
     * rows of the section under 'mappings'.
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
        if (isset(self::MAPPING_ROWS[$section])) {
            $select = $connection->select()
                ->from($this->resource->getTableName(MappingResource::TABLE_NAME), ['website_id', 'COUNT(*)'])
                ->where('website_id IN (?)', $websiteIds)
                ->group('website_id');
            foreach (self::MAPPING_ROWS[$section] as $condition => $value) {
                $select->where($condition, $value);
            }
            foreach ($connection->fetchPairs($select) as $websiteId => $count) {
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
     * another account. A store view of the website that holds another
     * account of its own counts too (CON-470), except under per-language
     * Smaily accounts (mode A), whose store views hold the languages'
     * accounts by design.
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
        $storeWide = $this->storeWideAccount();
        if ($storeWide === null) {
            return false;
        }
        foreach ($this->accountsInUse()[$websiteId] ?? [] as $account) {
            if ($account !== $storeWide[0] . "\n" . $storeWide[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every website and store view uses one Smaily account, or none
     * (CON-470): at most one account among the accounts in use
     * (accountsInUse()).
     */
    public function usesOneAccount(): bool
    {
        $accounts = [];
        foreach ($this->accountsInUse() as $websiteAccounts) {
            foreach ($websiteAccounts as $account) {
                $accounts[$account] = true;
            }
        }

        return count($accounts) <= 1;
    }

    /**
     * The Smaily accounts each website uses, website id => accounts (its
     * normalized subdomain and API username, joined by a line break): the
     * website's own account, else the store-wide one, and the account of
     * each of its store views that holds a subdomain or API username of its
     * own (CON-470), the rest read from the website. The store views of a
     * website with per-language Smaily accounts (mode A) are left out:
     * those accounts are the website's languages, by design. A website with
     * no account at all uses none.
     *
     * @return array<int, string[]>
     */
    private function accountsInUse(): array
    {
        $connection = $this->resource->getConnection();
        $values = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('core_config_data'), ['scope', 'scope_id', 'path', 'value'])
                ->where('path IN (?)', [
                    Config::XML_PATH_SUBDOMAIN,
                    Config::XML_PATH_USERNAME,
                    Config::XML_PATH_MULTILINGUAL_MODE,
                ])
        ) as $row) {
            $values[(string)$row['scope']][(int)$row['scope_id']][(string)$row['path']] = (string)$row['value'];
        }
        $default = $values[ScopeConfigInterface::SCOPE_TYPE_DEFAULT][0] ?? [];
        $account = fn (string $subdomain, string $username): string => $subdomain === '' && $username === ''
            ? ''
            : $this->normalizer->normalize($subdomain) . "\n" . trim($username);

        $accounts = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int)$website->getId();
            $own = $values[ScopeInterface::SCOPE_WEBSITES][$websiteId] ?? [];
            $subdomain = $own[Config::XML_PATH_SUBDOMAIN] ?? $default[Config::XML_PATH_SUBDOMAIN] ?? '';
            $username = $own[Config::XML_PATH_USERNAME] ?? $default[Config::XML_PATH_USERNAME] ?? '';
            $accounts[$websiteId] = array_values(array_filter([$account($subdomain, $username)]));
            $mode = $own[Config::XML_PATH_MULTILINGUAL_MODE] ?? $default[Config::XML_PATH_MULTILINGUAL_MODE] ?? '';
            if ($mode === MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS || !$website instanceof Website) {
                continue;
            }
            foreach ($website->getStoreIds() as $storeId) {
                $store = $values[ScopeInterface::SCOPE_STORES][(int)$storeId] ?? [];
                if (!isset($store[Config::XML_PATH_SUBDOMAIN]) && !isset($store[Config::XML_PATH_USERNAME])) {
                    continue;
                }
                $storeAccount = $account(
                    $store[Config::XML_PATH_SUBDOMAIN] ?? $subdomain,
                    $store[Config::XML_PATH_USERNAME] ?? $username
                );
                if ($storeAccount !== '') {
                    $accounts[$websiteId][] = $storeAccount;
                }
            }
        }

        return $accounts;
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
     * Whether any scope holds per-language Smaily accounts (multilingual
     * mode A): a saved mode A at the default scope or at a website's scope.
     */
    public function usesPerLanguageAccounts(): bool
    {
        $connection = $this->resource->getConnection();

        return (bool)$connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('core_config_data'), ['config_id'])
                ->where('path = ?', Config::XML_PATH_MULTILINGUAL_MODE)
                ->where('value = ?', MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS)
                ->where('scope IN (?)', [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, ScopeInterface::SCOPE_WEBSITES])
                ->limit(1)
        );
    }

    /**
     * ownValues() without the paths every website keeps of its own
     * (PER_WEBSITE): the values a website holds that the websites of one
     * store on one Smaily account share (CON-470). A website that holds
     * only such paths holds none.
     *
     * @return array<int, array<string, string>>
     */
    public function sharedOwnValues(string $section): array
    {
        $shared = [];
        foreach ($this->ownValues($section) as $websiteId => $values) {
            $values = array_diff_key($values, array_flip(self::PER_WEBSITE));
            if ($values !== []) {
                $shared[$websiteId] = $values;
            }
        }

        return $shared;
    }

    /**
     * Whether the default scope holds a value of the section that the
     * websites share (not a PER_WEBSITE path): a default-scope row of its
     * paths, or for the automations and the transactional emails a
     * store-wide mapping row (website 0) of the section.
     */
    public function hasStoreWideValues(string $section): bool
    {
        if (array_diff_key($this->storeWideValues($section), array_flip(self::PER_WEBSITE)) !== []) {
            return true;
        }
        if (!isset(self::MAPPING_ROWS[$section])) {
            return false;
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(MappingResource::TABLE_NAME), ['id'])
            ->where('website_id = ?', 0)
            ->limit(1);
        foreach (self::MAPPING_ROWS[$section] as $condition => $value) {
            $select->where($condition, $value);
        }

        return (bool)$connection->fetchOne($select);
    }

    /**
     * Whether a value of the path is saved for the website — a row at its
     * website scope or at the default scope — rather than only the module's
     * etc/config.xml default, which a read returns the same way (CON-461).
     * STORE_WIDE (0) asks the default scope.
     */
    public function isSaved(string $path, int $websiteId): bool
    {
        $connection = $this->resource->getConnection();

        return (bool)$connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('core_config_data'), ['config_id'])
                ->where('path = ?', $path)
                ->where(
                    $connection->quoteInto('scope = ?', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
                    . ' OR (' . $connection->quoteInto('scope = ?', ScopeInterface::SCOPE_WEBSITES)
                    . ' AND ' . $connection->quoteInto('scope_id = ?', $websiteId) . ')'
                )
                ->limit(1)
        );
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
     *
     * The transactional emails (CON-281) follow their own account, not the
     * Smaily account: a website without transactional workflows of its own
     * and without its own transactional account reads every store-wide
     * transactional row (WizardData::getTransactionalTriggers()), so it gets
     * them all; any other website gets none.
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
        if ($section === self::TRANSACTIONAL && !isset($own['mappings'])
            && !isset($own[Config::XML_PATH_TRANSACTIONAL_WEBSITE_ACCOUNT])
        ) {
            $this->replaceTransactionalRows(0, $websiteId);
        }
    }

    /**
     * Makes a website's values of the section the store-wide ones (CON-279,
     * separate stores back to one store): each of its own rows is written
     * at the default scope as stored, and for the automations its own
     * mapping rows join the store-wide rows (website 0), its own winning.
     * The store-wide values never take per-language Smaily accounts: mode A
     * becomes Single language there, and the rows of the per-language
     * accounts stay the website's. The transactional emails' store-wide rows
     * become the rows the website reads: its own, where it holds any or uses
     * its own transactional account (CON-281). The mark of a website's own
     * transactional account is not written at the default scope, nor are
     * the $keep paths. The website's own values stay until removeOwnValues().
     *
     * @param string[] $keep paths that stay the website's own
     */
    public function promoteToStoreWide(string $section, int $websiteId, array $keep = []): void
    {
        $own = $this->ownValues($section)[$websiteId] ?? [];
        foreach ($own as $path => $value) {
            if ($path === 'mappings' || $path === Config::XML_PATH_TRANSACTIONAL_WEBSITE_ACCOUNT
                || in_array($path, $keep, true)
            ) {
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
        if ($section === self::TRANSACTIONAL
            && (isset($own['mappings']) || isset($own[Config::XML_PATH_TRANSACTIONAL_WEBSITE_ACCOUNT]))
        ) {
            $this->replaceTransactionalRows($websiteId, 0);
        }
    }

    /**
     * Removes the store-wide values of the section (CON-279, one store to
     * separate stores, once every website holds them as its own): its
     * default-scope rows, and for the automations and the transactional
     * emails the section's store-wide mapping rows (website 0).
     */
    public function removeStoreWideValues(string $section): void
    {
        foreach (self::PATHS[$section] as $path) {
            $this->configWriter->delete($path);
        }
        $this->deleteMappingRows($section, 0);
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
     * Removes a website's own values of the section, with its mapping rows
     * of the section, so it follows the store-wide values again. For the
     * Smaily account, with $storeViewAccounts, its store views' own accounts
     * (multilingual mode A, which it set for itself) go as well. The $keep
     * paths stay.
     *
     * @param string[] $keep
     */
    public function removeOwnValues(
        string $section,
        int $websiteId,
        bool $storeViewAccounts = false,
        array $keep = []
    ): void {
        foreach (array_diff(self::PATHS[$section], $keep) as $path) {
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
        $this->deleteMappingRows($section, $websiteId);
    }

    /**
     * Deletes the section's workflow mapping rows of one website id (0: the
     * store-wide rows); a section without mapping rows has none.
     */
    private function deleteMappingRows(string $section, int $websiteId): void
    {
        if (!isset(self::MAPPING_ROWS[$section])) {
            return;
        }
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(MappingResource::TABLE_NAME),
            ['website_id = ?' => $websiteId] + self::MAPPING_ROWS[$section]
        );
    }

    /**
     * Gives one website id the transactional rows of another, in place of
     * its own (CON-281): a website reads its transactional rows as one set
     * (WizardData::getTransactionalTriggers()), so they are never merged.
     */
    private function replaceTransactionalRows(int $fromWebsiteId, int $toWebsiteId): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(MappingResource::TABLE_NAME);
        $select = $connection->select()
            ->from($table, ['trigger_type', 'language', 'account_key', 'workflow_id', 'is_default_fallback'])
            ->where('website_id = ?', $fromWebsiteId);
        foreach (self::MAPPING_ROWS[self::TRANSACTIONAL] as $condition => $value) {
            $select->where($condition, $value);
        }
        $rows = $connection->fetchAll($select);
        $this->deleteMappingRows(self::TRANSACTIONAL, $toWebsiteId);
        foreach ($rows as $row) {
            $connection->insert($table, ['website_id' => $toWebsiteId] + $row);
        }
    }

    /**
     * Removes the workflows picked from one Smaily account of a scope
     * (CON-396): a workflow belongs to the account it was picked from, so
     * an account that another Smaily account replaces takes its workflows
     * with it. $websiteId null is the store-wide scope (the default scope,
     * the mapping rows of website 0). $accountKey names the account:
     * Mapping::ACCOUNT_DEFAULT is the scope's one account, whose workflows
     * are also the automations' workflows saved at the scope (set to none
     * where the scope holds one); null is every per-language account (mode
     * A) of the website; else a language's own account. A row without a
     * workflow (MappingSaver's hiding row) names no account's workflow and
     * stays. Returns whether any workflow was removed.
     */
    public function removeWorkflows(?int $websiteId, ?string $accountKey): bool
    {
        $connection = $this->resource->getConnection();
        $removed = false;
        if ($accountKey === Mapping::ACCOUNT_DEFAULT) {
            $scope = $websiteId === null ? ScopeConfigInterface::SCOPE_TYPE_DEFAULT : ScopeInterface::SCOPE_WEBSITES;
            foreach ($connection->fetchPairs(
                $connection->select()
                    ->from($this->resource->getTableName('core_config_data'), ['path', 'value'])
                    ->where('scope = ?', $scope)
                    ->where('scope_id = ?', $websiteId ?? 0)
                    ->where('path IN (?)', self::WORKFLOW_PATHS)
            ) as $path => $value) {
                if ((int)$value > 0) {
                    $this->configWriter->save((string)$path, '0', $scope, $websiteId ?? 0);
                    $removed = true;
                }
            }
        }
        $where = [
            'website_id = ?' => $websiteId ?? 0,
            'trigger_type IN (?)' => Trigger::ALL,
            'workflow_id > ?' => 0,
        ];
        if ($accountKey === null) {
            $where['account_key NOT IN (?)'] = [Mapping::ACCOUNT_DEFAULT, Mapping::ACCOUNT_TRANSACTIONAL];
        } else {
            $where['account_key = ?'] = $accountKey;
        }

        return $connection->delete($this->resource->getTableName(MappingResource::TABLE_NAME), $where) > 0
            || $removed;
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
