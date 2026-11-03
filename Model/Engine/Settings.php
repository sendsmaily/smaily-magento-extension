<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\ScopeInterface;

/**
 * Campaign Intelligence connection settings. The API key is encrypted at
 * rest; endpoint URLs always come from the stored endpoints map — never
 * concatenated (contract §1).
 *
 * The DI instance is the primary connection's (Engine\Connections): its
 * rows are at the default scope, as they have been since the first
 * release. withWebsite() binds a copy to one website's scope — the
 * connection of a separate store (CON-237) — and every read, write and
 * delete of that copy goes to that scope. Magento reads a website scope
 * with the default scope as fallback, so a website-scope copy never
 * deletes a single path: it writes every path, '' for none, and so never
 * reads the primary's refusal, pushed workflows or browse tracking. It
 * marks its scope (XML_PATH_WEBSITE_CONNECTION), and only clear() removes
 * its rows, all of them.
 */
class Settings
{
    public const XML_PATH_CONNECTED = 'smaily_connect/intelligence/connected';
    public const XML_PATH_TENANT_ID = 'smaily_connect/intelligence/tenant_id';
    public const XML_PATH_TENANT_NAME = 'smaily_connect/intelligence/tenant_name';
    public const XML_PATH_API_KEY = 'smaily_connect/intelligence/api_key';
    public const XML_PATH_BASE_URL = 'smaily_connect/intelligence/engine_base_url';
    public const XML_PATH_ENGINE_VERSION = 'smaily_connect/intelligence/engine_version';
    public const XML_PATH_ENDPOINTS = 'smaily_connect/intelligence/endpoints';
    public const XML_PATH_CONFIG = 'smaily_connect/intelligence/config';
    public const XML_PATH_ISSUED_AT = 'smaily_connect/intelligence/issued_at';
    public const XML_PATH_BROWSE_TRACKING = 'smaily_connect/intelligence/browse_tracking';

    /**
     * The engine refused this account outright — contract §2 `403
     * tenant_inactive`: the API key is valid, the account is not (operator
     * suspension or a GDPR purge; the wire never tells the two apart, and the
     * plugin's reaction is the same either way). Holds the FIRST refusal's
     * GMT timestamp, so the merchant can be told when sending stopped.
     */
    public const XML_PATH_REFUSED_AT = 'smaily_connect/intelligence/refused_at';

    /**
     * The Smaily workflow and account the engine pushed for each email of
     * the extension (contract §23), as JSON — EmailDesignWorkflows keeps it.
     * A setup exchange and a disconnect delete it: the ids belong to the
     * connection that read them.
     */
    public const XML_PATH_EMAIL_WORKFLOWS = 'smaily_connect/intelligence/email_workflows';

    /**
     * The Smaily account the connection was made for (CON-237), as a
     * normalized subdomain: the setup exchange's `smaily_subdomain`
     * (contract v1.25.0 §1) when it names one, else the connecting
     * account's. Engine\Connections groups websites by it.
     */
    public const XML_PATH_SMAILY_SUBDOMAIN = 'smaily_connect/intelligence/smaily_subdomain';

    /**
     * '1' at the website scope of a website that holds a connection of its
     * own (CON-237). Never written at the default scope, so a website-scope
     * read of it has no value to fall back to.
     */
    public const XML_PATH_WEBSITE_CONNECTION = 'smaily_connect/intelligence/website_connection';

    /** Every path a website-scope connection holds; its clear() removes them all. */
    public const WEBSITE_PATHS = [
        self::XML_PATH_CONNECTED,
        self::XML_PATH_TENANT_ID,
        self::XML_PATH_TENANT_NAME,
        self::XML_PATH_API_KEY,
        self::XML_PATH_BASE_URL,
        self::XML_PATH_ENGINE_VERSION,
        self::XML_PATH_ENDPOINTS,
        self::XML_PATH_CONFIG,
        self::XML_PATH_ISSUED_AT,
        self::XML_PATH_BROWSE_TRACKING,
        self::XML_PATH_REFUSED_AT,
        self::XML_PATH_EMAIL_WORKFLOWS,
        self::XML_PATH_SMAILY_SUBDOMAIN,
        self::XML_PATH_WEBSITE_CONNECTION,
    ];

    /** In-request memo: a refusal recorded mid-run must stop the rest of it. */
    private ?bool $refused = null;

    /** In-request memo: the decrypted key, so one request decrypts once. */
    private ?string $apiKey = null;

    /** The website whose scope this copy is bound to; null for the primary (default scope). */
    private ?int $websiteId = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly EncryptorInterface $encryptor,
        private readonly TypeListInterface $cacheTypeList,
        private readonly Json $serializer,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * A copy bound to a website's scope (CON-237), with in-request memos of
     * its own; this instance stays as it is.
     *
     * @throws \InvalidArgumentException for a website id below 1
     */
    public function withWebsite(int $websiteId): self
    {
        if ($websiteId < 1) {
            throw new \InvalidArgumentException('A website connection needs a website id above 0.');
        }
        $copy = clone $this;
        $copy->websiteId = $websiteId;
        $copy->refused = null;
        $copy->apiKey = null;

        return $copy;
    }

    /** The website whose scope these settings are, or null for the primary's default scope. */
    public function getWebsiteId(): ?int
    {
        return $this->websiteId;
    }

    /**
     * Whether the website of a website-scope copy holds a connection of its
     * own (the mark storeExchange() writes). Always false for the primary.
     */
    public function isWebsiteConnection(): bool
    {
        return $this->websiteId !== null && $this->flag(self::XML_PATH_WEBSITE_CONNECTION);
    }

    public function isConnected(): bool
    {
        if ($this->websiteId !== null && !$this->isWebsiteConnection()) {
            return false;
        }

        return $this->flag(self::XML_PATH_CONNECTED) && $this->getApiKey() !== '';
    }

    /**
     * Has the engine refused this account outright? Recorded by Engine\Client
     * on a `403 tenant_inactive`, cleared by the next authenticated call that
     * succeeds (the health-check ping, or the admin's "Check again").
     * It can change within a request — an engine call records or clears it.
     *
     * @phpstan-impure
     */
    public function isRefused(): bool
    {
        return $this->refused ??= $this->getRefusedAt() !== '';
    }

    /** GMT timestamp of the FIRST refusal, empty when not refused. */
    public function getRefusedAt(): string
    {
        return (string)$this->value(self::XML_PATH_REFUSED_AT);
    }

    /**
     * The gate every SENDING path consults (PRO-2451, Woo PRO-1893 parity):
     * connected AND not refused. isConnected() stays the gate for everything
     * that only enqueues, reads or displays — queued rows wait for the
     * account to come back, they are never burned on a verdict.
     */
    public function isSendingAllowed(): bool
    {
        return $this->isConnected() && !$this->isRefused();
    }

    /**
     * Why a queued engine row may not be sent now, or null when it may: the
     * sending gate with the reason a queue handler reports on the row.
     */
    public function sendingBlockedReason(): ?string
    {
        if ($this->isRefused()) {
            return 'Campaign Intelligence account is not active';
        }

        return $this->isConnected() ? null : 'Campaign Intelligence is not connected';
    }

    /**
     * Remember the refusal. Keeps the first timestamp: the merchant wants to
     * know when sending stopped, not when it was last attempted.
     */
    public function recordRefusal(): void
    {
        if ($this->isRefused()) {
            return;
        }

        $this->write(self::XML_PATH_REFUSED_AT, $this->dateTime->gmtDate());
        $this->refused = true;
        $this->cleanConfigCache();
    }

    public function clearRefusal(): void
    {
        if (!$this->isRefused()) {
            return;
        }

        $this->unsetValue(self::XML_PATH_REFUSED_AT);
        $this->refused = false;
        $this->cleanConfigCache();
    }

    public function getTenantId(): string
    {
        return (string)$this->value(self::XML_PATH_TENANT_ID);
    }

    public function getTenantName(): string
    {
        return (string)$this->value(self::XML_PATH_TENANT_NAME);
    }

    public function getApiKey(): string
    {
        if ($this->apiKey === null) {
            $encrypted = (string)$this->value(self::XML_PATH_API_KEY);
            $this->apiKey = $encrypted === '' ? '' : $this->encryptor->decrypt($encrypted);
        }

        return $this->apiKey;
    }

    public function getEngineBaseUrl(): string
    {
        return (string)$this->value(self::XML_PATH_BASE_URL);
    }

    public function getEngineVersion(): string
    {
        return (string)$this->value(self::XML_PATH_ENGINE_VERSION);
    }

    /** When the connection's API key was issued (contract §1, Z-suffix), empty when not stored. */
    public function getIssuedAt(): string
    {
        return (string)$this->value(self::XML_PATH_ISSUED_AT);
    }

    /**
     * The endpoints map from setup-exchange (absolute URLs, prefixed keys).
     *
     * @return array<string, string>
     */
    public function getEndpoints(): array
    {
        return $this->decodeJson((string)$this->value(self::XML_PATH_ENDPOINTS));
    }

    /**
     * Resolve one endpoint from the stored map.
     */
    public function getEndpoint(string $key): ?string
    {
        $endpoints = $this->getEndpoints();
        $url = $endpoints[$key] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Tenant config from setup-exchange (cookie names, TTLs, rate limits).
     *
     * @return array<string, mixed>
     */
    public function getEngineConfig(): array
    {
        return $this->decodeJson((string)$this->value(self::XML_PATH_CONFIG));
    }

    public function isBrowseTrackingEnabled(): bool
    {
        return $this->isConnected() && $this->flag(self::XML_PATH_BROWSE_TRACKING);
    }

    /** The Smaily account the connection was made for (a normalized subdomain), '' when none is recorded. */
    public function getSmailySubdomain(): string
    {
        return (string)$this->value(self::XML_PATH_SMAILY_SUBDOMAIN);
    }

    /**
     * Record the Smaily account of a connection made before storeExchange()
     * recorded it (the data patch RecordEngineConnectionAccount).
     */
    public function recordSmailySubdomain(string $subdomain): void
    {
        $this->write(self::XML_PATH_SMAILY_SUBDOMAIN, $subdomain);
        $this->cleanConfigCache();
    }

    /**
     * Persist a successful setup-exchange response, with the Smaily account
     * the connection is made for: the answer's `smaily_subdomain` (contract
     * v1.25.0 §1) when it names one, else $accountSubdomain — the connecting
     * account's normalized subdomain.
     *
     * @param array<string, mixed> $response
     */
    public function storeExchange(array $response, string $accountSubdomain = ''): void
    {
        $engineAccount = $response['smaily_subdomain'] ?? null;
        $account = is_string($engineAccount) && trim($engineAccount) !== ''
            ? strtolower(trim($engineAccount))
            : $accountSubdomain;
        $reconnect = $this->isWebsiteConnection();

        $this->write(
            self::XML_PATH_API_KEY,
            $this->encryptor->encrypt((string)($response['api_key'] ?? ''))
        );
        $this->write(self::XML_PATH_TENANT_ID, (string)($response['tenant_id'] ?? ''));
        $this->write(self::XML_PATH_TENANT_NAME, (string)($response['tenant_name'] ?? ''));
        $this->write(self::XML_PATH_BASE_URL, (string)($response['engine_base_url'] ?? ''));
        $this->write(self::XML_PATH_ENGINE_VERSION, (string)($response['engine_version'] ?? ''));
        $this->write(
            self::XML_PATH_ENDPOINTS,
            $this->serializer->serialize($response['endpoints'] ?? [])
        );
        $this->write(
            self::XML_PATH_CONFIG,
            $this->serializer->serialize($response['config'] ?? [])
        );
        $this->write(self::XML_PATH_ISSUED_AT, (string)($response['issued_at'] ?? ''));
        $this->write(self::XML_PATH_CONNECTED, '1');
        // The pushed workflows of an earlier connection — maybe another
        // store's — are not this one's: the next §23 read brings its own.
        $this->unsetValue(self::XML_PATH_EMAIL_WORKFLOWS);
        $this->write(self::XML_PATH_SMAILY_SUBDOMAIN, $account);
        if ($this->websiteId !== null) {
            // A value of its own for every path: a first connect starts
            // with browse tracking off, a reconnect keeps the choice.
            if (!$reconnect) {
                $this->write(self::XML_PATH_BROWSE_TRACKING, '');
            }
            $this->write(self::XML_PATH_REFUSED_AT, '');
            $this->write(self::XML_PATH_WEBSITE_CONNECTION, '1');
            $this->refused = false;
        }
        $this->apiKey = null;
        // A fresh exchange is a live account by definition — and possibly a
        // different one, so a remembered refusal must not survive it.
        $this->clearRefusal();

        $this->cleanConfigCache();
    }

    /**
     * Remove the tenant connection (disconnect). A website-scope copy
     * removes every row of its scope (WEBSITE_PATHS); the primary keeps its
     * browse tracking choice for the next connect, as it always has.
     */
    public function clear(): void
    {
        $paths = $this->websiteId !== null ? self::WEBSITE_PATHS : [
            self::XML_PATH_CONNECTED,
            self::XML_PATH_TENANT_ID,
            self::XML_PATH_TENANT_NAME,
            self::XML_PATH_API_KEY,
            self::XML_PATH_BASE_URL,
            self::XML_PATH_ENGINE_VERSION,
            self::XML_PATH_ENDPOINTS,
            self::XML_PATH_CONFIG,
            self::XML_PATH_ISSUED_AT,
            self::XML_PATH_REFUSED_AT,
            self::XML_PATH_EMAIL_WORKFLOWS,
            self::XML_PATH_SMAILY_SUBDOMAIN,
        ];
        foreach ($paths as $path) {
            $this->delete($path);
        }
        $this->apiKey = null;
        $this->refused = false;

        $this->cleanConfigCache();
    }

    /**
     * A value of this connection's scope: the default scope for the
     * primary, else the website's (Magento falls back to the default scope
     * where the website holds no row; storeExchange() writes every path).
     */
    private function value(string $path): mixed
    {
        return $this->websiteId === null
            ? $this->scopeConfig->getValue($path)
            : $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_WEBSITE, $this->websiteId);
    }

    private function flag(string $path): bool
    {
        return $this->websiteId === null
            ? $this->scopeConfig->isSetFlag($path)
            : $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_WEBSITE, $this->websiteId);
    }

    private function write(string $path, string $value): void
    {
        if ($this->websiteId === null) {
            $this->configWriter->save($path, $value);

            return;
        }
        $this->configWriter->save($path, $value, ScopeInterface::SCOPE_WEBSITES, $this->websiteId);
    }

    /**
     * Unset one value: the primary deletes its row; a website-scope copy
     * writes '' instead, so it never reads the default scope's value.
     */
    private function unsetValue(string $path): void
    {
        if ($this->websiteId === null) {
            $this->configWriter->delete($path);

            return;
        }
        $this->write($path, '');
    }

    /** Delete one row of this connection's scope (clear()). */
    private function delete(string $path): void
    {
        if ($this->websiteId === null) {
            $this->configWriter->delete($path);

            return;
        }
        $this->configWriter->delete($path, ScopeInterface::SCOPE_WEBSITES, $this->websiteId);
    }

    private function cleanConfigCache(): void
    {
        $this->cacheTypeList->cleanType(\Magento\Framework\App\Cache\Type\Config::TYPE_IDENTIFIER);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = $this->serializer->unserialize($raw);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
