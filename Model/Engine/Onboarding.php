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
 * The two key-less calls behind "Try Intelligence free" (contract §21,
 * CON-363, as the WooCommerce plugin's CON-175): "Is the start open?"
 * (`GET /api/v1/onboarding/status`) and the start itself
 * (`POST /api/v1/onboarding/start`). Both go to the engine a new store
 * starts on (Client::DEFAULT_SETUP_BASE_URL); neither has an endpoints map
 * key, because the store has no connection yet.
 *
 * Each is one attempt with no redirect and no retry: the status is asked
 * while an admin waits for a banner, and the start's body carries the main
 * Smaily account's API password, which must never follow a redirect to
 * another host. Nothing here logs: not the body, not the answer, not the
 * one-time start link.
 */
class Onboarding
{
    public const PATH_STATUS = '/api/v1/onboarding/status';
    public const PATH_START = '/api/v1/onboarding/start';

    /** §21: keep the status timeout short, so that an admin never waits on it. */
    private const STATUS_TIMEOUT_SECONDS = 3;

    /** The start checks the Smaily login with one Smaily call before it answers. */
    private const START_TIMEOUT_SECONDS = 10;

    private const CONNECT_TIMEOUT_SECONDS = 2;

    public function __construct(
        private readonly HttpClientFactory $httpClientFactory
    ) {
    }

    /**
     * The engine's answer to "Is the start open?": true only for a 200 whose
     * body is `{"start_open": true}`; any other answer, an error or a
     * timeout is false.
     */
    public function isStartOpen(): bool
    {
        try {
            $response = $this->httpClientFactory->create()->request(
                'GET',
                Client::DEFAULT_SETUP_BASE_URL . self::PATH_STATUS,
                $this->options(self::STATUS_TIMEOUT_SECONDS)
            );
        } catch (GuzzleException) {
            return false;
        }
        if ($response->getStatusCode() !== 200) {
            return false;
        }
        $body = json_decode((string)$response->getBody(), true);

        return is_array($body) && ($body['start_open'] ?? null) === true;
    }

    /**
     * Ask the engine for a one-time start link. Never throws: the answer is
     * the HTTP status (0 when none arrived) and the decoded body ([] when it
     * is not JSON). A redirect is not followed, so it arrives as its own
     * 3xx status.
     *
     * @param array<string, string> $body the §21 request body
     * @return array{status: int, body: array<string, mixed>}
     */
    public function start(array $body): array
    {
        $options = $this->options(self::START_TIMEOUT_SECONDS);
        $options[RequestOptions::JSON] = $body;

        try {
            $response = $this->httpClientFactory->create()->request(
                'POST',
                Client::DEFAULT_SETUP_BASE_URL . self::PATH_START,
                $options
            );
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
        } catch (GuzzleException) {
            return ['status' => 0, 'body' => []];
        }
        $decoded = json_decode((string)$response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(int $timeoutSeconds): array
    {
        return [
            RequestOptions::TIMEOUT => $timeoutSeconds,
            RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::HEADERS => [
                'User-Agent' => ModuleInfo::USER_AGENT,
                'Accept' => 'application/json',
            ],
        ];
    }
}
