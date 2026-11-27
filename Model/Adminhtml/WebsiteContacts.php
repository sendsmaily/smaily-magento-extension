<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Smaily\Connect\Model\Config;

/**
 * The Contacts settings websites hold of their own where the websites run as
 * one store on one Smaily account (CON-470), compared with the settings for
 * all websites. Who is synced to Smaily is a consent question (owner
 * decision 2026-11-27, "Kinnitus enne"): a website's own Contacts settings
 * that differ in it — contact sync on or off, the sync mode (the lawful
 * basis), the guest buyers, the checkout opt-in, or the mark that its
 * Contacts settings were saved — stay in effect until the merchant confirms
 * on Settings > Contacts that every website uses the settings for all
 * websites. Own settings that differ in nothing of it are removed without
 * asking (the extra fields and the opt-in emails follow the settings for
 * all websites).
 *
 * Read from the tables, not the configuration cache: a save in this request
 * may just have written the settings for all websites. A path the default
 * scope holds no row of reads as the module's default.
 */
class WebsiteContacts
{
    /** The Contacts settings whose difference is a consent question. */
    public const CONSENT_PATHS = [
        Config::XML_PATH_SYNC_ENABLED,
        Config::XML_PATH_SYNC_MODE,
        Config::XML_PATH_INCLUDE_GUESTS,
        Config::XML_PATH_CHECKOUT_OPTIN_ENABLED,
        Config::XML_PATH_CONTACTS_CONFIRMED,
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WebsiteOverrides $overrides
    ) {
    }

    /**
     * The websites with own Contacts settings that differ from the settings
     * for all websites in a consent path, website id => whether its contact
     * sync is on and its sync mode, in website order.
     *
     * @return array<int, array{enabled: bool, mode: string}>
     */
    public function differing(): array
    {
        $differing = [];
        foreach ($this->compare() as $websiteId => [$differs, $values]) {
            if ($differs) {
                $differing[$websiteId] = [
                    'enabled' => $values[Config::XML_PATH_SYNC_ENABLED] === '1',
                    'mode' => $values[Config::XML_PATH_SYNC_MODE],
                ];
            }
        }

        return $differing;
    }

    /**
     * The websites with own Contacts settings that differ from the settings
     * for all websites in no consent path. $own: the websites' own Contacts
     * settings (WebsiteOverrides::ownValues()) when the caller has read
     * them already.
     *
     * @param array<int, array<string, string>>|null $own
     * @return int[]
     */
    public function identical(?array $own = null): array
    {
        $identical = [];
        foreach ($this->compare($own) as $websiteId => [$differs]) {
            if (!$differs) {
                $identical[] = $websiteId;
            }
        }

        return $identical;
    }

    /**
     * Each website with own Contacts settings: whether a consent path
     * differs, and its effective consent values; $own as in identical().
     *
     * @param array<int, array<string, string>>|null $own
     * @return array<int, array{0: bool, 1: array<string, string>}>
     */
    private function compare(?array $own = null): array
    {
        $own ??= $this->overrides->ownValues(WebsiteOverrides::SUBSCRIBERS);
        if ($own === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $storeWide = [];
        $confirmed = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('core_config_data'), ['scope', 'scope_id', 'path', 'value'])
                ->where('path IN (?)', self::CONSENT_PATHS)
                ->where('scope IN (?)', [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, ScopeInterface::SCOPE_WEBSITES])
        ) as $row) {
            if ($row['scope'] === ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
                $storeWide[(string)$row['path']] = (string)$row['value'];
            } elseif ($row['path'] === Config::XML_PATH_CONTACTS_CONFIRMED) {
                $confirmed[(int)$row['scope_id']] = (string)$row['value'];
            }
        }
        foreach (self::CONSENT_PATHS as $path) {
            $storeWide[$path] = $this->normalize(
                $path,
                $storeWide[$path] ?? (string)$this->scopeConfig->getValue($path)
            );
        }

        $compared = [];
        foreach ($own as $websiteId => $values) {
            $values[Config::XML_PATH_CONTACTS_CONFIRMED] = $confirmed[$websiteId]
                ?? $storeWide[Config::XML_PATH_CONTACTS_CONFIRMED];
            $effective = [];
            $differs = false;
            foreach (self::CONSENT_PATHS as $path) {
                $effective[$path] = isset($values[$path]) ? $this->normalize($path, $values[$path]) : $storeWide[$path];
                $differs = $differs || $effective[$path] !== $storeWide[$path];
            }
            $compared[$websiteId] = [$differs, $effective];
        }

        return $compared;
    }

    /**
     * A consent path's value as compared: the sync mode as text, every
     * other consent path as on/off.
     */
    private function normalize(string $path, string $value): string
    {
        return $path === Config::XML_PATH_SYNC_MODE ? trim($value) : ((bool)(int)$value ? '1' : '0');
    }
}
