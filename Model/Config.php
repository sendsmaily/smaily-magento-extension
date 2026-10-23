<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Smaily\Connect\Model\Automation\Trigger;

/**
 * Typed accessors for Smaily Connect configuration.
 *
 * Connection credentials read at store-view scope so that per-language
 * Smaily accounts (multilingual mode A) can override them per store view;
 * store views inherit website and default scope values as usual.
 */
class Config
{
    public const XML_PATH_SUBDOMAIN = 'smaily_connect/connection/subdomain';
    public const XML_PATH_USERNAME = 'smaily_connect/connection/username';
    public const XML_PATH_PASSWORD = 'smaily_connect/connection/password';
    public const XML_PATH_MULTILINGUAL_MODE = 'smaily_connect/connection/multilingual_mode';
    public const XML_PATH_FALLBACK_LANGUAGE = 'smaily_connect/connection/fallback_language';
    public const XML_PATH_STOREFRONT_URL = 'smaily_connect/connection/storefront_url';
    public const XML_PATH_SYNC_ENABLED = 'smaily_connect/subscribers/sync_enabled';
    public const XML_PATH_SYNC_MODE = 'smaily_connect/subscribers/sync_mode';
    public const XML_PATH_CONTACTS_CONFIRMED = 'smaily_connect/internal/contacts_confirmed';
    public const XML_PATH_SYNC_FIELDS = 'smaily_connect/subscribers/sync_fields';
    public const XML_PATH_INCLUDE_GUESTS = 'smaily_connect/subscribers/include_guests';
    public const XML_PATH_CHECKOUT_OPTIN_ENABLED = 'smaily_connect/subscribers/checkout_optin_enabled';
    public const XML_PATH_SUPPRESS_OPTIN_EMAILS = 'smaily_connect/subscribers/suppress_optin_emails';
    public const XML_PATH_WELCOME_ENABLED = 'smaily_connect/automations/welcome_enabled';
    public const XML_PATH_WELCOME_WORKFLOW = 'smaily_connect/automations/welcome_workflow';
    public const XML_PATH_FIRST_ORDER_ENABLED = 'smaily_connect/automations/first_order_enabled';
    public const XML_PATH_FIRST_ORDER_WORKFLOW = 'smaily_connect/automations/first_order_workflow';
    public const XML_PATH_ABANDONED_ENABLED = 'smaily_connect/automations/abandoned_enabled';
    public const XML_PATH_ABANDONED_WORKFLOW = 'smaily_connect/automations/abandoned_workflow';
    public const XML_PATH_ABANDONED_CUTOFF = 'smaily_connect/automations/abandoned_cutoff';
    public const XML_PATH_WELCOME_COUPON_ENABLED = 'smaily_connect/automations/welcome_coupon_enabled';
    public const XML_PATH_WELCOME_COUPON_RULE = 'smaily_connect/automations/welcome_coupon_rule';
    public const XML_PATH_WELCOME_COUPON_DAYS = 'smaily_connect/automations/welcome_coupon_days';
    public const XML_PATH_FIRST_ORDER_COUPON_ENABLED = 'smaily_connect/automations/first_order_coupon_enabled';
    public const XML_PATH_FIRST_ORDER_COUPON_RULE = 'smaily_connect/automations/first_order_coupon_rule';
    public const XML_PATH_FIRST_ORDER_COUPON_DAYS = 'smaily_connect/automations/first_order_coupon_days';
    public const XML_PATH_ABANDONED_COUPON_ENABLED = 'smaily_connect/automations/abandoned_coupon_enabled';
    public const XML_PATH_ABANDONED_COUPON_RULE = 'smaily_connect/automations/abandoned_coupon_rule';
    public const XML_PATH_ABANDONED_COUPON_DAYS = 'smaily_connect/automations/abandoned_coupon_days';
    public const XML_PATH_TRANSACTIONAL_SUBDOMAIN = 'smaily_connect/transactional/subdomain';
    public const XML_PATH_TRANSACTIONAL_USERNAME = 'smaily_connect/transactional/username';
    public const XML_PATH_TRANSACTIONAL_PASSWORD = 'smaily_connect/transactional/password';
    public const XML_PATH_TRANSACTIONAL_WEBSITE_ACCOUNT = 'smaily_connect/transactional/website_account';
    public const XML_PATH_TRANSACTIONAL_ENABLED = 'smaily_connect/transactional/enabled';
    public const XML_PATH_TRANSACTIONAL_PERSONAL_DATA = 'smaily_connect/transactional/personal_data_enabled';
    public const XML_PATH_ORDER_CONFIRMATION_ENABLED = 'smaily_connect/transactional/order_confirmation_enabled';
    public const XML_PATH_SHIPMENT_CONFIRMATION_ENABLED = 'smaily_connect/transactional/shipment_confirmation_enabled';
    public const XML_PATH_RSS_ENABLED = 'smaily_connect/rss/enabled';
    public const XML_PATH_LOG_VERBOSITY = 'smaily_connect/logging/verbosity';

    public const MIN_ABANDONED_CUTOFF_MINUTES = 10;

    /**
     * Each automation's discount code settings (CON-198): the "Send a
     * discount code" switch, the cart price rule its codes come from and
     * how many days a code is valid, keyed by trigger.
     *
     * @var array<string, array{enabled: string, rule: string, days: string}>
     */
    public const COUPON_PATHS = [
        Trigger::WELCOME => [
            'enabled' => self::XML_PATH_WELCOME_COUPON_ENABLED,
            'rule' => self::XML_PATH_WELCOME_COUPON_RULE,
            'days' => self::XML_PATH_WELCOME_COUPON_DAYS,
        ],
        Trigger::FIRST_ORDER => [
            'enabled' => self::XML_PATH_FIRST_ORDER_COUPON_ENABLED,
            'rule' => self::XML_PATH_FIRST_ORDER_COUPON_RULE,
            'days' => self::XML_PATH_FIRST_ORDER_COUPON_DAYS,
        ],
        Trigger::ABANDONED_CART => [
            'enabled' => self::XML_PATH_ABANDONED_COUPON_ENABLED,
            'rule' => self::XML_PATH_ABANDONED_COUPON_RULE,
            'days' => self::XML_PATH_ABANDONED_COUPON_DAYS,
        ],
    ];

    /** The longest a discount code may be valid, in days. */
    public const MAX_COUPON_DAYS = 365;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Get the Smaily account subdomain.
     *
     * @param int|string|null $storeId
     */
    public function getSubdomain(int|string|null $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_SUBDOMAIN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Get the Smaily API username.
     *
     * @param int|string|null $storeId
     */
    public function getUsername(int|string|null $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_USERNAME,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Get the decrypted Smaily API password.
     *
     * @param int|string|null $storeId
     */
    public function getPassword(int|string|null $storeId = null): string
    {
        $encrypted = (string)$this->scopeConfig->getValue(
            self::XML_PATH_PASSWORD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $encrypted === '' ? '' : $this->encryptor->decrypt($encrypted);
    }

    /**
     * The Smaily subdomain saved for a website (its own, else the default
     * scope's) — the scope a single-account Connection save writes to.
     */
    public function getWebsiteSubdomain(int $websiteId): string
    {
        return trim((string)$this->websiteValue(self::XML_PATH_SUBDOMAIN, $websiteId));
    }

    /**
     * The Smaily API username saved for a website (its own, else the
     * default scope's).
     */
    public function getWebsiteUsername(int $websiteId): string
    {
        return trim((string)$this->websiteValue(self::XML_PATH_USERNAME, $websiteId));
    }

    /**
     * The decrypted Smaily API password saved for a website (its own, else
     * the default scope's).
     */
    public function getWebsitePassword(int $websiteId): string
    {
        $encrypted = (string)$this->websiteValue(self::XML_PATH_PASSWORD, $websiteId);

        return $encrypted === '' ? '' : $this->encryptor->decrypt($encrypted);
    }

    /**
     * Whether a complete set of API credentials is configured for the scope.
     *
     * @param int|string|null $storeId
     */
    public function isConnected(int|string|null $storeId = null): bool
    {
        return $this->getSubdomain($storeId) !== ''
            && $this->getUsername($storeId) !== ''
            && $this->getPassword($storeId) !== '';
    }

    /**
     * The address of a separate (headless) storefront as saved for the
     * store's website ('' when there is none) — see StorefrontUrl.
     *
     * @param int|string|null $storeId
     */
    public function getStorefrontUrl(int|string|null $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_STOREFRONT_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Whether a separate storefront's address is saved for the store's
     * website (getStorefrontUrl() is not '').
     */
    public function hasStorefrontUrl(?int $storeId = null): bool
    {
        return $this->getStorefrontUrl($storeId) !== '';
    }

    /**
     * Get configured log verbosity (error|info|debug).
     */
    public function getLogVerbosity(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_LOG_VERBOSITY);
    }

    /**
     * Get the multilingual routing mode (single|a|b|c) for a website.
     */
    public function getMultilingualMode(?int $websiteId = null): string
    {
        return (string)$this->websiteValue(self::XML_PATH_MULTILINGUAL_MODE, $websiteId);
    }

    /**
     * The language whose per-language account is a website's default
     * fallback in multilingual mode A (informational — its credentials are
     * also the website's account, saved by the same save). Saved per
     * website since PRO-3719; a value saved before that, at the default
     * scope, is still read for a website without its own.
     */
    public function getFallbackLanguage(?int $websiteId = null): string
    {
        return trim((string)$this->websiteValue(self::XML_PATH_FALLBACK_LANGUAGE, $websiteId));
    }

    public function isSyncEnabled(?int $websiteId = null): bool
    {
        return $this->websiteFlag(self::XML_PATH_SYNC_ENABLED, $websiteId);
    }

    /**
     * Whether contacts of a website go to Smaily: its contact sync is on
     * and its Contacts settings were saved — the setup's Contacts step or
     * Settings > Contacts (PRO-4036), or the upgrade marked them saved for a
     * website that synced before. Every contact-sync path (the observers,
     * the consent reconcile, the checkout checkbox, the contacts import)
     * asks this; each still checks the credentials of the store it sends
     * through.
     */
    public function isContactSyncActive(int $websiteId): bool
    {
        return $this->isSyncEnabled($websiteId)
            && $this->websiteFlag(self::XML_PATH_CONTACTS_CONFIRMED, $websiteId);
    }

    /**
     * Get the contact-sync lawful-basis preset for a website.
     */
    public function getSyncMode(?int $websiteId = null): string
    {
        return (string)$this->websiteValue(self::XML_PATH_SYNC_MODE, $websiteId);
    }

    /**
     * Get enabled optional sync fields.
     *
     * @return string[]
     */
    public function getSyncFields(?int $websiteId = null): array
    {
        $raw = (string)$this->websiteValue(self::XML_PATH_SYNC_FIELDS, $websiteId);

        return $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function includeGuests(?int $websiteId = null): bool
    {
        return $this->websiteFlag(self::XML_PATH_INCLUDE_GUESTS, $websiteId);
    }

    public function isCheckoutOptinEnabled(?int $websiteId = null): bool
    {
        return $this->websiteFlag(self::XML_PATH_CHECKOUT_OPTIN_ENABLED, $websiteId);
    }

    public function suppressOptinEmails(?int $websiteId = null): bool
    {
        return $this->websiteFlag(self::XML_PATH_SUPPRESS_OPTIN_EMAILS, $websiteId);
    }

    public function isWelcomeEnabled(?int $websiteId = null): bool
    {
        return $this->websiteFlag(self::XML_PATH_WELCOME_ENABLED, $websiteId);
    }

    public function getWelcomeWorkflow(?int $websiteId = null): int
    {
        return (int)$this->websiteValue(self::XML_PATH_WELCOME_WORKFLOW, $websiteId);
    }

    public function isFirstOrderEnabled(?int $websiteId = null): bool
    {
        return $this->websiteFlag(self::XML_PATH_FIRST_ORDER_ENABLED, $websiteId);
    }

    public function getFirstOrderWorkflow(?int $websiteId = null): int
    {
        return (int)$this->websiteValue(self::XML_PATH_FIRST_ORDER_WORKFLOW, $websiteId);
    }

    public function isAbandonedCartEnabled(?int $websiteId = null): bool
    {
        return $this->websiteFlag(self::XML_PATH_ABANDONED_ENABLED, $websiteId);
    }

    public function getAbandonedCartWorkflow(?int $websiteId = null): int
    {
        return (int)$this->websiteValue(self::XML_PATH_ABANDONED_WORKFLOW, $websiteId);
    }

    /**
     * Get abandoned cart cutoff in minutes (never below the safe minimum).
     */
    public function getAbandonedCutoffMinutes(?int $websiteId = null): int
    {
        return max(
            self::MIN_ABANDONED_CUTOFF_MINUTES,
            (int)$this->websiteValue(self::XML_PATH_ABANDONED_CUTOFF, $websiteId)
        );
    }

    /**
     * Whether the automation's emails carry a discount code (CON-198).
     */
    public function isCouponEnabled(string $trigger, int $websiteId): bool
    {
        return isset(self::COUPON_PATHS[$trigger])
            && $this->websiteFlag(self::COUPON_PATHS[$trigger]['enabled'], $websiteId);
    }

    /**
     * The cart price rule the automation's discount codes come from (0: none).
     */
    public function getCouponRuleId(string $trigger, int $websiteId): int
    {
        return isset(self::COUPON_PATHS[$trigger])
            ? (int)$this->websiteValue(self::COUPON_PATHS[$trigger]['rule'], $websiteId)
            : 0;
    }

    /**
     * How many days after sending the automation's discount code is valid
     * (1 to MAX_COUPON_DAYS).
     */
    public function getCouponValidDays(string $trigger, int $websiteId): int
    {
        $days = isset(self::COUPON_PATHS[$trigger])
            ? (int)$this->websiteValue(self::COUPON_PATHS[$trigger]['days'], $websiteId)
            : 0;

        return max(1, min(self::MAX_COUPON_DAYS, $days));
    }

    /**
     * The transactional-email Smaily account's subdomain for a website
     * (PRO-4062): the website's own account when it has one, else the
     * account saved for all websites — which null reads (default scope).
     */
    public function getTransactionalSubdomain(?int $websiteId): string
    {
        return trim((string)$this->transactionalValue(self::XML_PATH_TRANSACTIONAL_SUBDOMAIN, $websiteId));
    }

    /**
     * The transactional-email account's API username for a website, or for
     * all websites (null).
     */
    public function getTransactionalUsername(?int $websiteId): string
    {
        return trim((string)$this->transactionalValue(self::XML_PATH_TRANSACTIONAL_USERNAME, $websiteId));
    }

    /**
     * The transactional-email account's decrypted API password for a
     * website, or for all websites (null).
     */
    public function getTransactionalPassword(?int $websiteId): string
    {
        $encrypted = (string)$this->transactionalValue(self::XML_PATH_TRANSACTIONAL_PASSWORD, $websiteId);

        return $encrypted === '' ? '' : $this->encryptor->decrypt($encrypted);
    }

    /**
     * Whether a website uses its own transactional-email account instead of
     * the one saved for all websites. Written at website scope only.
     */
    public function hasTransactionalWebsiteAccount(int $websiteId): bool
    {
        return $this->websiteFlag(self::XML_PATH_TRANSACTIONAL_WEBSITE_ACCOUNT, $websiteId);
    }

    /**
     * The "Send transactional emails" switch of a website (off by default),
     * the WooCommerce plugin's master switch. Nothing reads it to send yet.
     */
    public function isTransactionalEnabled(int $websiteId): bool
    {
        return $this->websiteFlag(self::XML_PATH_TRANSACTIONAL_ENABLED, $websiteId);
    }

    /**
     * The Customer details switch of a website's transactional emails: the
     * addresses, phone and order note go with both confirmations (off by
     * default; the WooCommerce plugin's PRO-3190). Nothing reads it to send
     * yet.
     */
    public function isTransactionalPersonalDataEnabled(int $websiteId): bool
    {
        return $this->websiteFlag(self::XML_PATH_TRANSACTIONAL_PERSONAL_DATA, $websiteId);
    }

    /**
     * The Order confirmation switch of a website's transactional emails
     * (off by default). Nothing reads it to send yet.
     */
    public function isOrderConfirmationEnabled(int $websiteId): bool
    {
        return $this->websiteFlag(self::XML_PATH_ORDER_CONFIRMATION_ENABLED, $websiteId);
    }

    /**
     * The Shipping confirmation switch of a website's transactional emails
     * (off by default). Nothing reads it to send yet.
     */
    public function isShipmentConfirmationEnabled(int $websiteId): bool
    {
        return $this->websiteFlag(self::XML_PATH_SHIPMENT_CONFIRMATION_ENABLED, $websiteId);
    }

    /**
     * Whether the public product RSS feed is enabled for the current store.
     */
    public function isRssEnabled(int|string|null $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_RSS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    private function transactionalValue(string $path, ?int $websiteId): mixed
    {
        return $websiteId === null ? $this->scopeConfig->getValue($path) : $this->websiteValue($path, $websiteId);
    }

    private function websiteValue(string $path, ?int $websiteId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_WEBSITE, $websiteId);
    }

    private function websiteFlag(string $path, ?int $websiteId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_WEBSITE, $websiteId);
    }
}
