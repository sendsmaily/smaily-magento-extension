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
    public const XML_PATH_SYNC_ENABLED = 'smaily_connect/subscribers/sync_enabled';
    public const XML_PATH_SYNC_MODE = 'smaily_connect/subscribers/sync_mode';
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
    public const XML_PATH_RSS_ENABLED = 'smaily_connect/rss/enabled';
    public const XML_PATH_LOG_VERBOSITY = 'smaily_connect/logging/verbosity';

    public const MIN_ABANDONED_CUTOFF_MINUTES = 10;

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
     * The language whose per-language account is the default fallback in
     * multilingual mode A (informational — its credentials are also stored
     * at the default scope by the save path).
     */
    public function getFallbackLanguage(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_FALLBACK_LANGUAGE));
    }

    public function isSyncEnabled(?int $websiteId = null): bool
    {
        return $this->websiteFlag(self::XML_PATH_SYNC_ENABLED, $websiteId);
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

    private function websiteValue(string $path, ?int $websiteId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_WEBSITE, $websiteId);
    }

    private function websiteFlag(string $path, ?int $websiteId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_WEBSITE, $websiteId);
    }
}
