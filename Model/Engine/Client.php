<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\RequestOptions;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Client\ExchangeResponse;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Client\TransportErrorMessage;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\ModuleInfo;
use Smaily\Connect\Model\StorefrontScript;

/**
 * Campaign Intelligence engine client (RECENGINE_API_CONTRACT.md v1.2).
 *
 * - Bearer auth; the API key never reaches client-side code.
 * - Endpoint URLs come from the stored endpoints map, never concatenated.
 * - Retry policy per contract: exponential backoff 1/2/4/8/16s (max 5) on
 *   429 (honouring retry_after_seconds from the body, up to 60 s) and 5xx;
 *   other 4xx never retry. The storefront browse relay is the exception:
 *   one short attempt, never a retry or a wait (relayBrowse()).
 * - D6 ingest responses are per-item: a 200 is never all-or-nothing.
 * - Exception messages are translated with __(): they surface in the admin
 *   UI (wizard step 4, automations form, health notices).
 */
class Client
{
    public const DOMAIN_CATALOG = 'catalog';
    public const DOMAIN_CUSTOMERS = 'customers';
    public const DOMAIN_ORDERS = 'orders';
    public const DOMAIN_BROWSE = 'browse';

    /**
     * §3b product-level removal queue domain (PRO-1231). Deliberately NOT in
     * DOMAIN_WRAPPERS/DOMAIN_BATCH_LIMITS: catalog/remove is not a D6 ingest
     * route (no per-item errors[]) and is flushed by its own path in
     * Cron\FlushIngestQueue, never by ingest().
     */
    public const DOMAIN_CATALOG_REMOVE = 'catalog_remove';

    /** Spec-conservative §3b batch ceiling (the wrapper allows up to 1000 ids; mirrors Woo). */
    public const CATALOG_REMOVE_BATCH_LIMIT = 100;

    public const DOMAIN_WRAPPERS = [
        self::DOMAIN_CATALOG => 'products',
        self::DOMAIN_CUSTOMERS => 'customers',
        self::DOMAIN_ORDERS => 'orders',
        self::DOMAIN_BROWSE => 'events',
    ];

    public const DOMAIN_BATCH_LIMITS = [
        self::DOMAIN_CATALOG => 100,
        self::DOMAIN_CUSTOMERS => 100,
        self::DOMAIN_ORDERS => 50,
        self::DOMAIN_BROWSE => 100,
    ];

    public const DEFAULT_SETUP_BASE_URL = 'https://intelligence.smaily.com';

    /**
     * The hosts a setup address and the engine's setup reply may name: the
     * Smaily engine's production host (contract "Base context"; the setup
     * default of the WooCommerce and Shopify plugins). Always over https.
     */
    public const ENGINE_HOSTS = ['intelligence.smaily.com'];
    public const COMPATIBLE_ENGINE_MAJOR = 1;

    private const RETRY_DELAYS_SECONDS = [1, 2, 4, 8, 16];
    private const TIMEOUT_SECONDS = 30;
    private const CONNECT_TIMEOUT_SECONDS = 10;

    /** The ceiling on a back-off the engine asks for (429 retry_after_seconds). */
    private const MAX_RETRY_AFTER_SECONDS = 60;

    /** The storefront relay's one attempt: short enough for a shopper's request. */
    private const RELAY_TIMEOUT_SECONDS = 3;
    private const RELAY_CONNECT_TIMEOUT_SECONDS = 2;

    /**
     * @var array{request: array<int|string, mixed>, response: array{http_status: int, body: mixed}|null}|null
     */
    private ?array $lastExchange = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly HttpClientFactory $httpClientFactory,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly StoreManagerInterface $storeManager,
        private readonly Logger $logger,
        private readonly SleeperInterface $sleeper,
        private readonly StorefrontScript $storefrontScript
    ) {
    }

    /**
     * Exchange a one-time setup token (or full setup URL) for tenant
     * credentials. The caller persists the response via Settings.
     *
     * Only an https address on ENGINE_HOSTS is called, and a reply is
     * returned only when its engine base URL and every endpoint are https
     * addresses on ENGINE_HOSTS too.
     *
     * @return array<string, mixed>
     */
    public function setupExchange(string $setupInput): array
    {
        [$baseUrl, $token] = $this->parseSetupInput($setupInput);
        if (!$this->isEngineUrl($baseUrl)) {
            throw new EngineRequestException(
                (string)__('The setup URL must be an https address on %1.', implode(', ', self::ENGINE_HOSTS)),
                400
            );
        }
        if ($token === '') {
            throw new EngineRequestException((string)__('Setup token is empty or unrecognized'), 400);
        }

        $response = $this->request('POST', $baseUrl . '/api/setup/exchange', [
            'setup_token' => $token,
            'plugin_info' => $this->pluginInfo(),
        ], false);

        $endpoints = $response['endpoints'] ?? [];
        $urls = is_array($endpoints) ? array_values($endpoints) : [$endpoints];
        if (array_key_exists('engine_base_url', $response)) {
            $urls[] = $response['engine_base_url'];
        }
        foreach ($urls as $url) {
            if (!is_string($url) || !$this->isEngineUrl($url)) {
                throw new EngineRequestException(
                    (string)__(
                        'The engine answered with an address that is not an https address on %1,'
                        . ' so the connection was not saved.',
                        implode(', ', self::ENGINE_HOSTS)
                    ),
                    400
                );
            }
        }

        return $response;
    }

    /**
     * Health/tenant check.
     *
     * @return array<string, mixed>
     */
    public function ping(): array
    {
        return $this->request('GET', $this->endpoint('ingest_ping'), null);
    }

    /**
     * Send one ingest batch. Returns the D6 response
     * {ok, processed, deduplicated, errors[]}.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function ingest(string $domain, array $items): array
    {
        $wrapper = self::DOMAIN_WRAPPERS[$domain] ?? null;
        if ($wrapper === null) {
            throw new EngineRequestException((string)__('Unknown ingest domain "%1"', $domain), 400);
        }

        return $this->request(
            'POST',
            $this->endpoint('ingest_' . $domain),
            [$wrapper => array_values($items)]
        );
    }

    /**
     * Forward storefront browse events from inside a shopper's request:
     * one attempt with a short timeout, no retry and no back-off wait.
     * Browse events are loss-tolerant (never queued), so an engine that is
     * slow, failing or asking the store to slow down costs the batch, never
     * a storefront worker.
     *
     * @param array<int, array<string, mixed>> $events
     * @return array<string, mixed>
     */
    public function relayBrowse(array $events): array
    {
        return $this->request(
            'POST',
            $this->endpoint('ingest_' . self::DOMAIN_BROWSE),
            [self::DOMAIN_WRAPPERS[self::DOMAIN_BROWSE] => array_values($events)],
            true,
            true
        );
    }

    /**
     * Product-level soft removal — POST /api/v1/ingest/catalog/remove
     * (contract §3b, PRO-1231). Tombstones every catalog row whose
     * `tags.product_id` matches (in_stock=false + recommendable=false; rows
     * are kept — the engine never hard-deletes catalog). The path for a
     * platform HARD delete, where the product's SKUs may no longer be
     * enumerable.
     *
     * `$productIds` carry the RAW platform parent product ids — exactly the
     * `tags.product_id` strings the catalog sync emits
     * (ParentProductResolver::productIdOf()), never the `sku`.
     *
     * Idempotent: a re-removed or never-ingested id lands in the response's
     * `not_found` — a contract-defined success, not an error. Response:
     * {ok, removed_products, rows_tombstoned, not_found}. NOT a D6 shape.
     *
     * The endpoints map carries `ingest_catalog_remove` since contract
     * v1.4.0; tenants exchanged earlier fall back to the hardcoded path
     * (contract §1 "map age" — mirrors the Woo fallback).
     *
     * @param string[] $productIds 1..1000 raw parent product ids
     * @return array<string, mixed>
     */
    public function catalogRemove(array $productIds): array
    {
        return $this->request(
            'POST',
            $this->endpoint('ingest_catalog_remove', '/api/v1/ingest/catalog/remove'),
            ['product_ids' => array_values($productIds)]
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function identityMerge(array $payload): array
    {
        return $this->request('POST', $this->endpoint('identity_merge'), $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function customerExport(string $email): array
    {
        return $this->request('GET', $this->customerEndpoint('customer_export', $email), null);
    }

    /**
     * Idempotent erase: a 404 means the customer is already gone.
     *
     * @return array<string, mixed>
     */
    public function customerDelete(string $email): array
    {
        try {
            return $this->request('DELETE', $this->customerEndpoint('customer_delete', $email), [
                'confirm' => true,
                'reason' => 'user_request',
            ]);
        } catch (EngineRequestException $exception) {
            if ($exception->getHttpStatus() === 404) {
                return ['ok' => true, 'already_deleted' => true];
            }
            throw $exception;
        }
    }

    /**
     * Profiling opt-out/opt-in (contract §10). The opt-in reversal carries
     * no opted_out_at; the reason vocabulary is §10's (user_preference),
     * not §9's delete reasons.
     *
     * @return array<string, mixed>
     */
    public function customerOptOut(string $email, bool $optOut, string $reason, string $timestamp): array
    {
        $body = [
            'opt_out' => $optOut,
            'reason' => $reason,
        ];
        if ($optOut) {
            $body['opted_out_at'] = $timestamp;
        }

        return $this->request('POST', $this->customerEndpoint('customer_opt_out', $email), $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function automationsCatalog(): array
    {
        return $this->request('GET', $this->endpoint('automations_catalog', '/api/v1/automations/catalog'), null);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAutomationsConfig(): array
    {
        return $this->request('GET', $this->endpoint('automations_config', '/api/v1/automations/config'), null);
    }

    /**
     * All-or-nothing validation: a 422 means nothing was saved.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function putAutomationsConfig(array $rows): array
    {
        return $this->request(
            'PUT',
            $this->endpoint('automations_config', '/api/v1/automations/config'),
            ['configs' => array_values($rows)]
        );
    }

    /**
     * Resolve an endpoint from the stored map, with an optional fallback
     * path for keys added after the tenant's exchange (contract §1 map age).
     */
    private function endpoint(string $key, ?string $fallbackPath = null): string
    {
        $url = $this->settings->getEndpoint($key);
        if ($url !== null) {
            return $url;
        }

        $baseUrl = rtrim($this->settings->getEngineBaseUrl(), '/');
        if ($fallbackPath !== null && $baseUrl !== '') {
            return $baseUrl . $fallbackPath;
        }

        throw new EngineRequestException((string)__('Engine endpoint "%1" is not available', $key), 400);
    }

    /**
     * Customer endpoints carry a literal {email} placeholder — substitute
     * with string replace, never printf-style (contract §1).
     */
    private function customerEndpoint(string $key, string $email): string
    {
        return str_replace('{email}', rawurlencode(strtolower(trim($email))), $this->endpoint($key));
    }

    /**
     * The last request's body and the final reply to it, after any retries —
     * null until a request was made, and a null reply when none arrived. The
     * queues store it on the row for the Log's Details (PRO-1965); the API
     * key is never part of it.
     *
     * @return array{request: array<int|string, mixed>, response: array{http_status: int, body: mixed}|null}|null
     */
    public function lastExchange(): ?array
    {
        return $this->lastExchange;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param bool $singleAttempt one short attempt, no retry and no wait (the storefront relay)
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $url,
        ?array $body,
        bool $authenticated = true,
        bool $singleAttempt = false
    ): array {
        $this->lastExchange = null;
        $retryDelays = $singleAttempt ? [] : self::RETRY_DELAYS_SECONDS;
        $options = [
            RequestOptions::TIMEOUT => $singleAttempt ? self::RELAY_TIMEOUT_SECONDS : self::TIMEOUT_SECONDS,
            RequestOptions::CONNECT_TIMEOUT => $singleAttempt
                ? self::RELAY_CONNECT_TIMEOUT_SECONDS
                : self::CONNECT_TIMEOUT_SECONDS,
            RequestOptions::HEADERS => [
                'User-Agent' => ModuleInfo::USER_AGENT,
                'Accept' => 'application/json',
            ],
        ];
        if ($authenticated) {
            $apiKey = $this->settings->getApiKey();
            if ($apiKey === '') {
                throw new EngineRequestException((string)__('Campaign Intelligence is not connected'), 401);
            }
            $options[RequestOptions::HEADERS]['Authorization'] = 'Bearer ' . $apiKey;
        }
        if ($body !== null) {
            $options[RequestOptions::JSON] = $body;
        }

        $httpClient = $this->httpClientFactory->create();
        $attempt = 0;
        $this->lastExchange = ['request' => $body ?? [], 'response' => null];

        // Retry loop per contract: backoff 1/2/4/8/16s on 429 and 5xx.
        while (true) {
            try {
                $this->lastExchange['response'] = null;
                $response = $httpClient->request($method, $url, $options);
                $this->lastExchange['response'] = ExchangeResponse::of(
                    $response->getStatusCode(),
                    (string)$response->getBody()
                );
                $this->checkEngineVersion($response->getHeaderLine('X-Engine-Version'));
                if ($authenticated) {
                    // The account answered, so a remembered refusal is over
                    // (PRO-2451). Cleared here for the same reason it is
                    // recorded here: this is the one place every engine call
                    // passes through, so no caller can forget either half.
                    $this->settings->clearRefusal();
                }

                return $this->decode((string)$response->getBody());
            } catch (BadResponseException $exception) {
                $status = $exception->getResponse()->getStatusCode();
                $rawBody = (string)$exception->getResponse()->getBody();
                $this->lastExchange['response'] = ExchangeResponse::of($status, $rawBody);
                $errorBody = $this->decodeSafely($rawBody);

                if ($status === 403 && ($errorBody['error'] ?? '') === 'tenant_inactive') {
                    // Contract §2: the key is valid, the account is not.
                    // Remembered as connection state so every send path can
                    // stop asking — retries cannot clear it, only Smaily can.
                    $this->settings->recordRefusal();
                }

                if ($status !== 429 && $status < 500) {
                    throw new EngineRequestException(
                        (string)__(
                            'Engine request failed with HTTP %1: %2',
                            $status,
                            (string)($errorBody['message'] ?? $errorBody['error'] ?? 'unknown error')
                        ),
                        $status,
                        $errorBody
                    );
                }

                if ($attempt >= count($retryDelays)) {
                    throw new EngineTransportException(
                        (string)__('Engine request failed with HTTP %1 after retries', $status),
                        $status,
                        $exception
                    );
                }

                $delay = $retryDelays[$attempt];
                if ($status === 429 && isset($errorBody['retry_after_seconds'])) {
                    $delay = max(
                        $delay,
                        min((int)$errorBody['retry_after_seconds'], self::MAX_RETRY_AFTER_SECONDS)
                    );
                }
                $this->sleeper->sleep($delay);
                $attempt++;
            } catch (GuzzleException $exception) {
                if ($attempt >= count($retryDelays)) {
                    // The customer endpoints carry the address in the URL that
                    // ends Guzzle's message: only the masked text goes on, and
                    // the raw exception is not chained (PRO-3572).
                    throw new EngineTransportException(
                        (string)__('Engine request failed: %1', TransportErrorMessage::of($exception))
                    );
                }
                $this->sleeper->sleep($retryDelays[$attempt]);
                $attempt++;
            }
        }
    }

    /**
     * Graceful degradation on version mismatch: warn, never refuse to
     * operate (data loss beats a compatibility notice).
     */
    private function checkEngineVersion(string $version): void
    {
        if ($version === '') {
            return;
        }
        $major = (int)strtok($version, '.');
        if ($major !== self::COMPATIBLE_ENGINE_MAJOR) {
            $this->logger->info('Engine version outside supported range', [
                'engine_version' => $version,
                'supported_major' => self::COMPATIBLE_ENGINE_MAJOR,
            ]);
        }
    }

    /**
     * @return array{string, string} base URL, token
     */
    private function parseSetupInput(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return [self::DEFAULT_SETUP_BASE_URL, ''];
        }

        if (str_starts_with($input, 'http://') || str_starts_with($input, 'https://')) {
            $parts = parse_url($input);
            $host = (string)($parts['host'] ?? '');
            $path = (string)($parts['path'] ?? '');
            $segments = array_values(array_filter(explode('/', $path)));
            $token = $segments !== [] ? end($segments) : '';

            if ($host === '') {
                return [self::DEFAULT_SETUP_BASE_URL, $token];
            }

            // Preserve the pasted scheme and port (mirrors the Woo plugin's
            // parse_setup_url); setupExchange() then accepts only https on
            // ENGINE_HOSTS.
            $scheme = (string)($parts['scheme'] ?? 'https');
            $base = $scheme . '://' . $host;
            if (isset($parts['port'])) {
                $base .= ':' . $parts['port'];
            }

            return [$base, $token];
        }

        return [self::DEFAULT_SETUP_BASE_URL, $input];
    }

    /**
     * Whether a URL is an https address on ENGINE_HOSTS, read with the same
     * URI parser the request itself uses.
     */
    private function isEngineUrl(string $url): bool
    {
        try {
            $uri = new Uri($url);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $uri->getScheme() === 'https' && in_array($uri->getHost(), self::ENGINE_HOSTS, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function pluginInfo(): array
    {
        $magentoVersion = (string)$this->productMetadata->getVersion();

        return [
            'name' => 'smaily-connect-magento',
            'version' => ModuleInfo::VERSION,
            'platform' => 'magento',
            'platform_version' => $magentoVersion,
            'ecommerce_platform' => 'magento',
            'ecommerce_platform_version' => $magentoVersion,
            'site_url' => $this->siteUrl(),
        ];
    }

    /**
     * The default store view's storefront address, also when connected from
     * bin/magento with web server rewrites off (PRO-3733).
     */
    private function siteUrl(): string
    {
        try {
            $store = $this->storeManager->getDefaultStoreView();
            if ($store === null) {
                return '';
            }

            return $this->storefrontScript->apply((string)$store->getBaseUrl(), (int)$store->getId());
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new EngineTransportException((string)__('Engine returned a malformed response body'));
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSafely(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
