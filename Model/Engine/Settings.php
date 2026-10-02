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

/**
 * Campaign Intelligence tenant settings, stored at default scope (one engine
 * tenant per Magento installation, mirroring the Woo plugin's per-site
 * tenancy). The API key is encrypted at rest; endpoint URLs always come from
 * the stored endpoints map — never concatenated (contract §1).
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

    /** In-request memo: a refusal recorded mid-run must stop the rest of it. */
    private ?bool $refused = null;

    /** In-request memo: the decrypted key, so one request decrypts once. */
    private ?string $apiKey = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly EncryptorInterface $encryptor,
        private readonly TypeListInterface $cacheTypeList,
        private readonly Json $serializer,
        private readonly DateTime $dateTime
    ) {
    }

    public function isConnected(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONNECTED) && $this->getApiKey() !== '';
    }

    /**
     * Has the engine refused this account outright? Recorded by Engine\Client
     * on a `403 tenant_inactive`, cleared by the next authenticated call that
     * succeeds (the health-check ping, or the admin's "Check again").
     */
    public function isRefused(): bool
    {
        return $this->refused ??= $this->getRefusedAt() !== '';
    }

    /** GMT timestamp of the FIRST refusal, empty when not refused. */
    public function getRefusedAt(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_REFUSED_AT);
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

        $this->configWriter->save(self::XML_PATH_REFUSED_AT, $this->dateTime->gmtDate());
        $this->refused = true;
        $this->cleanConfigCache();
    }

    public function clearRefusal(): void
    {
        if (!$this->isRefused()) {
            return;
        }

        $this->configWriter->delete(self::XML_PATH_REFUSED_AT);
        $this->refused = false;
        $this->cleanConfigCache();
    }

    public function getTenantId(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_TENANT_ID);
    }

    public function getTenantName(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_TENANT_NAME);
    }

    public function getApiKey(): string
    {
        if ($this->apiKey === null) {
            $encrypted = (string)$this->scopeConfig->getValue(self::XML_PATH_API_KEY);
            $this->apiKey = $encrypted === '' ? '' : $this->encryptor->decrypt($encrypted);
        }

        return $this->apiKey;
    }

    public function getEngineBaseUrl(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_BASE_URL);
    }

    public function getEngineVersion(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_ENGINE_VERSION);
    }

    /**
     * The endpoints map from setup-exchange (absolute URLs, prefixed keys).
     *
     * @return array<string, string>
     */
    public function getEndpoints(): array
    {
        return $this->decodeJson((string)$this->scopeConfig->getValue(self::XML_PATH_ENDPOINTS));
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
        return $this->decodeJson((string)$this->scopeConfig->getValue(self::XML_PATH_CONFIG));
    }

    public function isBrowseTrackingEnabled(): bool
    {
        return $this->isConnected() && $this->scopeConfig->isSetFlag(self::XML_PATH_BROWSE_TRACKING);
    }

    /**
     * Persist a successful setup-exchange response.
     *
     * @param array<string, mixed> $response
     */
    public function storeExchange(array $response): void
    {
        $this->configWriter->save(
            self::XML_PATH_API_KEY,
            $this->encryptor->encrypt((string)($response['api_key'] ?? ''))
        );
        $this->configWriter->save(self::XML_PATH_TENANT_ID, (string)($response['tenant_id'] ?? ''));
        $this->configWriter->save(self::XML_PATH_TENANT_NAME, (string)($response['tenant_name'] ?? ''));
        $this->configWriter->save(self::XML_PATH_BASE_URL, (string)($response['engine_base_url'] ?? ''));
        $this->configWriter->save(self::XML_PATH_ENGINE_VERSION, (string)($response['engine_version'] ?? ''));
        $this->configWriter->save(
            self::XML_PATH_ENDPOINTS,
            $this->serializer->serialize($response['endpoints'] ?? [])
        );
        $this->configWriter->save(
            self::XML_PATH_CONFIG,
            $this->serializer->serialize($response['config'] ?? [])
        );
        $this->configWriter->save(self::XML_PATH_ISSUED_AT, (string)($response['issued_at'] ?? ''));
        $this->configWriter->save(self::XML_PATH_CONNECTED, '1');
        $this->apiKey = null;
        // A fresh exchange is a live account by definition — and possibly a
        // different one, so a remembered refusal must not survive it.
        $this->clearRefusal();

        $this->cleanConfigCache();
    }

    /**
     * Remove the tenant connection (disconnect).
     */
    public function clear(): void
    {
        foreach ([
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
        ] as $path) {
            $this->configWriter->delete($path);
        }
        $this->apiKey = null;
        $this->refused = false;

        $this->cleanConfigCache();
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
