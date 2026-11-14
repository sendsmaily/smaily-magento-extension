<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Report;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\MultilingualMode;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\ModuleInfo;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\StoreLayout;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * Builds the installation report body (CON-234): contract
 * installation-report-v1 (`erkkimarkus/subscription`,
 * docs/contracts/installation-report-v1.md), product `connect_magento`, as
 * the WooCommerce plugin's ReportBuilder (CON-290).
 *
 * One connection per website that has a connected Smaily account — one per
 * language account in multilingual mode A — with its website code, address,
 * features, health and the Campaign Intelligence engine store that serves
 * the website; and one `transactional` connection per website that has a
 * Smaily Transactional account. A connected account has a subdomain, an API
 * user and a password, and a subdomain that passes the contract's rule.
 *
 * The MAIN account authenticates the report: the default scope's account
 * when a connection names it, else the first connection's. A Transactional
 * account never does, so an installation connected to nothing but a
 * Transactional account reports nothing.
 *
 * It carries the contract's list and nothing more: no customer, order,
 * admin or free-text data, no credential (the main account's API user and
 * password go only into the Authorization header), no engine key, and error
 * CODES only.
 *
 * The removal (Setup\Uninstall) builds this without the module's di.xml
 * (CON-220): no constructor dependency here may need it
 * (Test/Unit/Setup/UninstallTest walks them). That is why the engine store
 * of a website is found here and not through Engine\Connections, whose
 * client needs the module's preferences; engineFor() follows its rules.
 */
class ReportBuilder
{
    public const PRODUCT = 'connect_magento';

    public const EVENT_SNAPSHOT = 'snapshot';
    public const EVENT_DISCONNECTED = 'disconnected';
    public const EVENT_DEACTIVATED = 'deactivated';

    public const TRIGGER_SETUP_SAVED = 'setup_saved';
    public const TRIGGER_DAILY = 'daily';

    public const ROLE_MARKETING = 'marketing';
    public const ROLE_TRANSACTIONAL = 'transactional';

    /** The contract's subdomain rule. */
    public const SUBDOMAIN_PATTERN = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/';

    /** The most connections a report carries (contract "Top-level fields"). */
    public const MAX_CONNECTIONS = 100;

    /** Each automation's feature id. */
    private const AUTOMATION_FEATURES = [
        Trigger::WELCOME => 'welcome_automation',
        Trigger::FIRST_ORDER => 'first_order_automation',
        Trigger::ABANDONED_CART => 'abandoned_cart',
    ];

    /** @var array<string, EngineSettings>|null the engine store owning each Smaily account, under separate stores */
    private ?array $engineOwners = null;

    public function __construct(
        private readonly Config $config,
        private readonly EngineSettings $engineSettings,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly StoreLayout $storeLayout,
        private readonly SubdomainNormalizer $normalizer,
        private readonly AccountResolver $accountResolver,
        private readonly Router $router,
        private readonly ConnectionHealth $health,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * The main account's credentials, or null when no Smaily marketing
     * account is connected — then nothing is reported.
     */
    public function mainCredentials(): ?Credentials
    {
        return $this->main($this->accounts());
    }

    /**
     * Each connected account as `role|subdomain`: a save that makes one of
     * them go deleted that account's credentials (a `disconnected` event).
     *
     * @return string[]
     */
    public function accountKeys(): array
    {
        $keys = [];
        foreach ($this->accounts() as $account) {
            $keys[] = $account['role'] . '|' . $account['credentials']->subdomain;
        }

        return array_values(array_unique($keys));
    }

    /**
     * The report, or null when there is none to send: no Smaily marketing
     * account is connected, or the installation has no valid address.
     *
     * A `disconnected` event that leaves no marketing account carries no
     * connection and is authenticated with $previousMain, the main account
     * the installation held before; without it there is nothing to send.
     *
     * @param string $event one of the EVENT_* constants
     * @param string $trigger a TRIGGER_* constant for a snapshot, '' otherwise
     * @param string[] $removedTenantIds engine stores a `disconnected` event removes
     */
    public function build(
        string $event,
        string $trigger = '',
        array $removedTenantIds = [],
        ?Credentials $previousMain = null
    ): ?Report {
        $this->engineOwners = null;
        $installationUrl = $this->installationUrl();
        if ($installationUrl === null) {
            return null;
        }

        $accounts = $this->accounts();
        $main = $this->main($accounts);
        if ($main === null) {
            if ($event !== self::EVENT_DISCONNECTED || $previousMain === null) {
                return null;
            }
            $main = $previousMain;
            $accounts = [];
        }

        $connections = [];
        foreach ($accounts as $account) {
            $connections[] = $this->connection($account, $removedTenantIds);
        }

        $body = [
            'product' => self::PRODUCT,
            'product_version' => self::clip(ModuleInfo::VERSION),
            'platforms' => [
                ['name' => 'magento', 'version' => self::clip((string)$this->productMetadata->getVersion())],
            ],
            'php_version' => self::clip(PHP_VERSION),
            'installation_url' => $installationUrl,
            'smaily_subdomain' => $main->subdomain,
            'event' => $event,
        ];
        if ($event === self::EVENT_SNAPSHOT) {
            $body['trigger'] = $trigger;
        }
        $body['sent_at'] = self::timestamp((int)$this->dateTime->gmtTimestamp());
        $body['connections'] = array_slice($connections, 0, self::MAX_CONNECTIONS);

        return new Report($body, $main);
    }

    /**
     * The connected accounts: per website (in id order) its marketing
     * account — each language account in mode A — then its transactional
     * account.
     *
     * @return array<int, array{role: string, website_id: int, website: string, language: string,
     *     store_url: string, credentials: Credentials}>
     */
    private function accounts(): array
    {
        $accounts = [];
        foreach ($this->websites() as $websiteId => $code) {
            $storeUrl = $this->websiteUrl($websiteId);
            if ($storeUrl === null) {
                continue;
            }
            $base = ['website_id' => $websiteId, 'website' => $code, 'store_url' => $storeUrl];

            if ($this->config->getMultilingualMode($websiteId) === MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS) {
                foreach ($this->accountResolver->detectedLanguages($websiteId) as $language) {
                    $storeId = $this->accountResolver->storeIdsForAccountKey($language, $websiteId)[0] ?? null;
                    $credentials = $storeId === null || preg_match('/^[a-z]{2}$/', $language) !== 1
                        ? null
                        : $this->connected(
                            $this->config->getSubdomain($storeId),
                            $this->config->getUsername($storeId),
                            $this->config->getPassword($storeId)
                        );
                    if ($credentials !== null) {
                        $accounts[] = $base + [
                            'role' => self::ROLE_MARKETING,
                            'language' => $language,
                            'credentials' => $credentials,
                        ];
                    }
                }
            } else {
                $credentials = $this->connected(
                    $this->config->getWebsiteSubdomain($websiteId),
                    $this->config->getWebsiteUsername($websiteId),
                    $this->config->getWebsitePassword($websiteId)
                );
                if ($credentials !== null) {
                    $accounts[] = $base + [
                        'role' => self::ROLE_MARKETING,
                        'language' => '',
                        'credentials' => $credentials,
                    ];
                }
            }

            $transactional = $this->connected(
                $this->config->getTransactionalSubdomain($websiteId),
                $this->config->getTransactionalUsername($websiteId),
                $this->config->getTransactionalPassword($websiteId)
            );
            if ($transactional !== null) {
                $accounts[] = $base + [
                    'role' => self::ROLE_TRANSACTIONAL,
                    'language' => '',
                    'credentials' => $transactional,
                ];
            }
        }

        return $accounts;
    }

    /**
     * The main account: the default scope's account when a marketing
     * connection names it, else the first marketing connection's account.
     *
     * @param array<int, array{role: string, credentials: Credentials}> $accounts
     */
    private function main(array $accounts): ?Credentials
    {
        $marketing = array_values(array_filter(
            $accounts,
            static fn (array $account): bool => $account['role'] === self::ROLE_MARKETING
        ));
        if ($marketing === []) {
            return null;
        }

        $default = $this->connected(
            $this->config->getWebsiteSubdomain(0),
            $this->config->getWebsiteUsername(0),
            $this->config->getWebsitePassword(0)
        );
        if ($default !== null) {
            foreach ($marketing as $account) {
                if ($account['credentials']->subdomain === $default->subdomain) {
                    return $default;
                }
            }
        }

        return $marketing[0]['credentials'];
    }

    /**
     * @param array{role: string, website_id: int, website: string, language: string, store_url: string,
     *     credentials: Credentials} $account
     * @param string[] $removedTenantIds
     * @return array<string, mixed>
     */
    private function connection(array $account, array $removedTenantIds): array
    {
        $subdomain = $account['credentials']->subdomain;
        $connection = [
            'role' => $account['role'],
            'store_url' => $account['store_url'],
            'website' => $account['website'],
            'smaily_subdomain' => $subdomain,
        ];
        if ($account['language'] !== '') {
            $connection['language'] = $account['language'];
        }

        $features = [];
        $engine = null;
        if ($account['role'] === self::ROLE_MARKETING) {
            $websiteId = $account['website_id'];
            if ($this->config->isContactSyncActive($websiteId)) {
                $features[] = 'subscriber_sync';
            }
            foreach (self::AUTOMATION_FEATURES as $trigger => $feature) {
                if ($this->automationOn($trigger, $websiteId, $account['language'])) {
                    $features[] = $feature;
                }
            }

            $engine = $this->engineFor($websiteId);
            if ($engine !== null && in_array($engine->getTenantId(), $removedTenantIds, true)) {
                $engine = null;
            }
            if ($engine !== null) {
                $features[] = 'campaign_intelligence';
                if ($engine->isBrowseTrackingEnabled()) {
                    $features[] = 'browse_tracking';
                }
            }
        }
        // A transactional connection names no feature: the extension sends
        // no order or shipping email through Smaily yet (ARCHITECTURE
        // "Transactional emails — settings only"), so `transactional_emails`
        // would say emails go that do not.

        $connection['features'] = $features;
        $health = $this->health->smaily($subdomain);
        $connection['smaily'] = [
            'status' => $health['status'],
            'last_sync_at' => self::timestampOrNull($health['last_sync_at']),
            'last_error_code' => $health['last_error_code'],
        ];

        if ($engine !== null) {
            $engineHealth = $this->health->engine($engine->getTenantId());
            $connection['intelligence'] = [
                'tenant_id' => $engine->getTenantId(),
                'connected' => $engine->isSendingAllowed(),
                'status' => $engineHealth['status'],
                'last_sync_at' => self::timestampOrNull($engineHealth['last_sync_at']),
                'last_error_code' => $engineHealth['last_error_code'],
            ];
        }

        return $connection;
    }

    /**
     * Whether an automation is switched on for the website AND mapped to a
     * workflow this account sends: a mode-A language account only its own
     * language's workflow, never another account's fallback.
     */
    private function automationOn(string $trigger, int $websiteId, string $language): bool
    {
        $enabled = match ($trigger) {
            Trigger::WELCOME => $this->config->isWelcomeEnabled($websiteId),
            Trigger::FIRST_ORDER => $this->config->isFirstOrderEnabled($websiteId),
            Trigger::ABANDONED_CART => $this->config->isAbandonedCartEnabled($websiteId),
            default => false,
        };
        if (!$enabled) {
            return false;
        }

        try {
            $match = $this->router->resolve(
                $trigger,
                $websiteId,
                $language !== '' ? $language : $this->accountResolver->defaultLanguage($websiteId)
            );
        } catch (\Exception) {
            // The mapping table is gone during the module's removal.
            return false;
        }

        return $match !== null
            && ($language === '' || $match->accountKey === null || $match->accountKey === $language);
    }

    /**
     * The connected engine store that serves a website, as
     * Engine\Connections::forWebsite() finds it: under one store the
     * store-wide connection serves every website; under separate stores the
     * website's Smaily account picks the connection that claimed it — the
     * store-wide one first, then each website's own in website-id order.
     */
    private function engineFor(int $websiteId): ?EngineSettings
    {
        if (!$this->storeLayout->isSeparateStores()) {
            $engine = $this->engineSettings;
        } else {
            $account = $this->normalizer->normalize($this->config->getWebsiteSubdomain($websiteId));
            $engine = $account === '' ? null : ($this->engineOwners()[$account] ?? null);
        }

        return $engine !== null && $engine->isConnected() && $engine->getTenantId() !== '' ? $engine : null;
    }

    /**
     * @return array<string, EngineSettings>
     */
    private function engineOwners(): array
    {
        if ($this->engineOwners !== null) {
            return $this->engineOwners;
        }

        $owners = [];
        $candidates = $this->engineSettings->isConnected() ? [$this->engineSettings] : [];
        foreach (array_keys($this->websites()) as $websiteId) {
            $own = $this->engineSettings->withWebsite($websiteId);
            if ($own->isWebsiteConnection()) {
                $candidates[] = $own;
            }
        }
        foreach ($candidates as $settings) {
            $account = $this->normalizer->normalize($settings->getSmailySubdomain());
            if ($account !== '' && !isset($owners[$account])) {
                $owners[$account] = $settings;
            }
        }

        return $this->engineOwners = $owners;
    }

    /**
     * The credentials when they make a connected account: all three set
     * and a subdomain that passes the contract's rule.
     */
    private function connected(string $subdomain, string $username, string $password): ?Credentials
    {
        $subdomain = $this->normalizer->normalize($subdomain);
        if ($subdomain === '' || $username === '' || $password === '' || strlen($subdomain) > 63
            || preg_match(self::SUBDOMAIN_PATTERN, $subdomain) !== 1
        ) {
            return null;
        }

        return new Credentials($subdomain, $username, $password);
    }

    /**
     * The websites by id (id order), with their codes.
     *
     * @return array<int, string>
     */
    private function websites(): array
    {
        $websites = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $code = (string)$website->getCode();
            if ((int)$website->getId() > 0 && $code !== '' && strlen($code) <= 64) {
                $websites[(int)$website->getId()] = $code;
            }
        }
        ksort($websites);

        return $websites;
    }

    /**
     * The installation's address: the base URL of the default store view,
     * the store the default scope opens.
     */
    private function installationUrl(): ?string
    {
        try {
            $store = $this->storeManager->getDefaultStoreView();
        } catch (\Exception) {
            return null;
        }

        return $store instanceof Store ? $this->address($store) : null;
    }

    /**
     * A website's address: the base URL of its default store view.
     */
    private function websiteUrl(int $websiteId): ?string
    {
        try {
            $website = $this->storeManager->getWebsite($websiteId);
        } catch (\Exception) {
            return null;
        }
        $store = $website instanceof Website ? $website->getDefaultStore() : null;

        return $store instanceof Store ? $this->address($store) : null;
    }

    /**
     * A store's web base URL as its storefront uses it (secure when the
     * storefront is) as the contract's address: an absolute http(s) URL
     * without credentials, query or fragment, without a trailing slash, at
     * most 255 characters; null when it is not one.
     */
    private function address(Store $store): ?string
    {
        $url = rtrim(trim((string)$store->getBaseUrl(UrlInterface::URL_TYPE_WEB, $store->isFrontUrlSecure())), '/');
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || strlen($url) > 255
        ) {
            return null;
        }

        return $url;
    }

    private static function timestamp(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private static function timestampOrNull(?int $timestamp): ?string
    {
        return $timestamp === null ? null : self::timestamp($timestamp);
    }

    /** The contract's 32-character limit on a version string. */
    private static function clip(string $value): string
    {
        return substr($value, 0, 32);
    }
}
