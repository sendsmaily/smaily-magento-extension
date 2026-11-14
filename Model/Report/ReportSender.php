<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Report;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\ModuleInfo;

/**
 * Sends one installation report to Smaily's subscription service (CON-234;
 * contract installation-report-v1 "Endpoint: POST /v1/reports").
 *
 * HTTPS only, the certificate verified, TLS 1.2 or newer; a connect timeout
 * of 3 seconds and a total of 5; never a redirect. The main account's API
 * user and password go in the Authorization header only. It answers what
 * the service said (SendResult); deciding whether to try again is the
 * caller's. It never throws.
 *
 * Tests, the integration suite and the docker sandbox never reach
 * ENDPOINT: tests give it an HttpClientFactory that answers, and the
 * sandbox resolves subscription.smaily.com to itself (docker-compose.yaml),
 * because a report there creates a real installation record.
 */
class ReportSender
{
    /** The receiver — the one place its address is written. */
    public const ENDPOINT = 'https://subscription.smaily.com/v1/reports';

    public const CONNECT_TIMEOUT_SECONDS = 3;
    public const TIMEOUT_SECONDS = 5;
    public const MAX_BODY_BYTES = 65536;

    /** The error code the service's answers use, and this class's own codes. */
    private const CODE_PATTERN = '/^[a-z0-9_]{1,64}$/';

    public function __construct(
        private readonly HttpClientFactory $httpClientFactory
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public function send(array $body, Credentials $credentials): SendResult
    {
        try {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new SendResult(0, 'invalid_json', false);
        }
        if (strlen($json) > self::MAX_BODY_BYTES) {
            return new SendResult(0, 'body_too_large', false);
        }

        try {
            $response = $this->httpClientFactory->create()->request('POST', self::ENDPOINT, [
                RequestOptions::BODY => $json,
                RequestOptions::HEADERS => [
                    'Authorization' => $credentials->authorization(),
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Accept' => 'application/json',
                    'User-Agent' => ModuleInfo::USER_AGENT,
                ],
                RequestOptions::TIMEOUT => self::TIMEOUT_SECONDS,
                RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::VERIFY => true,
                'curl' => [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2],
            ]);
        } catch (GuzzleException) {
            // A timeout or a network error: no answer came. Guzzle's message
            // is not kept — the next report carries the state.
            return new SendResult(0, ConnectionHealth::NETWORK_ERROR, true);
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return new SendResult($status, '', false);
        }

        $answer = json_decode((string)$response->getBody(), true);
        $error = is_array($answer) && is_string($answer['error'] ?? null)
            && preg_match(self::CODE_PATTERN, $answer['error']) === 1
            ? $answer['error']
            : '';
        if ($status >= 300 && $status < 400) {
            // A redirect means the base URL is wrong; it is never followed.
            return new SendResult($status, $error !== '' ? $error : 'redirect', false);
        }

        $retryAfter = $response->getHeaderLine('Retry-After');

        return new SendResult(
            $status,
            $error,
            $status === 429 || $status >= 500,
            ctype_digit($retryAfter) ? (int)$retryAfter : 0
        );
    }
}
