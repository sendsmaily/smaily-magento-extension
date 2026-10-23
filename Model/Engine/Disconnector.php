<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\ModuleInfo;

/**
 * Tells Campaign Intelligence that this store's connection ends — contract
 * §16 `POST /api/v1/store/uninstall` (v1.18.0) — in one place for both
 * paths that end it: `bin/magento smaily:engine:disconnect` and the
 * module's removal (Setup\Uninstall). The engine revokes the calling key;
 * a store left with no connection is paused, never deleted, and a new
 * setup exchange resumes it. Each caller deletes the stored key after this
 * call, whatever it returns: a refused key or an engine that does not
 * answer never blocks a disconnect or a removal.
 *
 * One attempt, no retry: a removal does not wait for an engine that does
 * not answer, and the engine's key just stays unused. The only dependency
 * resolves without the module's di.xml, because Magento builds the
 * removal's classes when the module may already be disabled.
 */
class Disconnector
{
    /** The engine answered 200: the store has no connection left and is paused. */
    public const PAUSED = 'store_paused';

    /** The engine answered 200: another connection of the store keeps working. */
    public const CONNECTION_REMOVED = 'connection_removed';

    /** The engine answered 200 with another status: the connection ended. */
    public const ENDED = 'ended';

    /** 401: the engine no longer accepts the key, so the connection had already ended (§16 Idempotency). */
    public const KEY_REFUSED = 'key_refused';

    /** No answer, or an error other than 401: the engine was not told. */
    public const NOT_TOLD = 'not_told';

    /** The endpoints map key (§1); a connection set up before v1.14.0 does not have it. */
    public const ENDPOINT_KEY = 'store_uninstall';

    public const FALLBACK_PATH = '/api/v1/store/uninstall';

    private const TIMEOUT_SECONDS = 10;
    private const CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly HttpClientFactory $httpClientFactory
    ) {
    }

    /**
     * End this connection on the engine with the connection's own key.
     *
     * @param string $apiKey the stored, decrypted engine API key
     * @param array<string, mixed> $endpoints the stored setup-exchange endpoints map
     * @param string $engineBaseUrl the stored engine base URL, for the fallback path
     * @return string one of the outcome constants
     */
    public function endConnection(string $apiKey, array $endpoints, string $engineBaseUrl): string
    {
        $url = $endpoints[self::ENDPOINT_KEY] ?? null;
        if ((!is_string($url) || $url === '') && $engineBaseUrl !== '') {
            $url = rtrim($engineBaseUrl, '/') . self::FALLBACK_PATH;
        }
        if ($apiKey === '' || !is_string($url) || $url === '') {
            return self::NOT_TOLD;
        }

        try {
            $response = $this->httpClientFactory->create()->request('POST', $url, [
                RequestOptions::TIMEOUT => self::TIMEOUT_SECONDS,
                RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                RequestOptions::HEADERS => [
                    'User-Agent' => ModuleInfo::USER_AGENT,
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                ],
            ]);
        } catch (BadResponseException $exception) {
            return $exception->getResponse()->getStatusCode() === 401 ? self::KEY_REFUSED : self::NOT_TOLD;
        } catch (GuzzleException) {
            return self::NOT_TOLD;
        }

        $body = json_decode((string)$response->getBody(), true);
        $status = is_array($body) ? ($body['status'] ?? null) : null;

        return in_array($status, [self::PAUSED, self::CONNECTION_REMOVED], true) ? $status : self::ENDED;
    }
}
