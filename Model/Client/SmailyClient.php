<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Smaily\Connect\Model\Client\Exception\ApiException;
use Smaily\Connect\Model\Client\Exception\AuthenticationException;
use Smaily\Connect\Model\Client\Exception\InvalidSubdomainException;
use Smaily\Connect\Model\Client\Exception\PlanBlockedException;
use Smaily\Connect\Model\Client\Exception\TransportException;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\ModuleInfo;
use Smaily\Connect\Model\SmailyUrl;

/**
 * Smaily marketing API client.
 *
 * Wire facts (shared with the WooCommerce and Shopify plugins):
 * - Base URL https://{subdomain}.sendsmaily.net/api/{endpoint}.php
 * - HTTP Basic authentication
 * - Success envelope: HTTP 200 with {"code": 101}; 203 invalid data,
 *   206 email not found. List endpoints return plain arrays.
 *
 * Instances are scope-bound (credentials are fixed at construction); use
 * SmailyClientProvider to obtain a client for a store view.
 *
 * Exception messages are translated with __(): they surface in the admin UI
 * (wizard step 1, config assist, workflow loading).
 */
class SmailyClient
{
    public const ENDPOINT_CONTACT = 'contact';
    public const ENDPOINT_AUTORESPONDER = 'autoresponder';
    public const ENDPOINT_WORKFLOWS = 'workflows';
    public const ENDPOINT_HISTORY = 'history';

    private const TIMEOUT_SECONDS = 30;
    private const CONNECT_TIMEOUT_SECONDS = 10;

    private ?HttpClient $httpClient = null;

    /**
     * @var array{request: array<int|string, mixed>, response: array{http_status: int, body: mixed}|null}|null
     */
    private ?array $lastExchange = null;

    public function __construct(
        private readonly HttpClientFactory $httpClientFactory,
        private readonly Logger $logger,
        private readonly VerifiedCredentials $verifiedCredentials,
        private readonly string $subdomain,
        private readonly string $username,
        private readonly string $password
    ) {
    }

    /**
     * Perform a GET request against an API endpoint.
     *
     * @param array<string, mixed> $query
     * @return array<int|string, mixed>
     */
    public function get(string $endpoint, array $query = []): array
    {
        return $this->request('GET', $endpoint, [RequestOptions::QUERY => $query]);
    }

    /**
     * Perform a POST request with a JSON payload against an API endpoint.
     *
     * @param array<int|string, mixed> $payload
     * @return array<int|string, mixed>
     * @throws ApiException on a non-101 response envelope
     * @throws TransportException on an HTTP error status or a network failure
     * @throws InvalidSubdomainException when the subdomain is not one plain label
     */
    public function post(string $endpoint, array $payload): array
    {
        return $this->request('POST', $endpoint, [RequestOptions::JSON => $payload]);
    }

    /**
     * List automation workflows that CAN be triggered through the API.
     *
     * Uses GET workflows.php?trigger_type=form_submitted (WooCommerce plugin
     * parity). POST autoresponder.php only enrolls workflows with the
     * "form submitted" trigger — every other workflow is rejected with the
     * misleading code 221 "Invalid autoresponder ID", even though it appears
     * ACTIVE in GET autoresponder.php (verified against production Smaily
     * 2026-07; listing via autoresponder.php offered untriggerable ids).
     * Disabled workflows are filtered out: enrolling one returns 101 OK but
     * silently sends nothing.
     *
     * @return array<int, array{id: int, title: string}>
     */
    public function getAutomationWorkflows(): array
    {
        $workflows = [];
        foreach ($this->get(self::ENDPOINT_WORKFLOWS, ['trigger_type' => 'form_submitted']) as $workflow) {
            if (!is_array($workflow) || !isset($workflow['id'])) {
                continue;
            }
            if (array_key_exists('is_enabled', $workflow) && !$workflow['is_enabled']) {
                continue;
            }
            $workflows[] = [
                'id' => (int)$workflow['id'],
                'title' => (string)($workflow['title'] ?? $workflow['name'] ?? $workflow['id']),
            ];
        }

        return $workflows;
    }

    /**
     * Validate credentials with a lightweight API call. A pass is remembered
     * as "Connected" for these credentials (PRO-3560).
     *
     * @throws AuthenticationException when credentials are rejected
     * @throws PlanBlockedException when the account's package has no API access
     * @throws TransportException on network failure
     * @throws InvalidSubdomainException when the subdomain is not one plain label
     */
    public function validateCredentials(): void
    {
        $this->get(self::ENDPOINT_WORKFLOWS, ['trigger_type' => 'form_submitted']);
        $this->verifiedCredentials->accept($this->subdomain, $this->username, $this->password);
    }

    /**
     * The last request's body and the reply to it — null until a request was
     * made, and a null reply when none arrived (a network failure). The queue
     * stores it on the row for the Log's Details (PRO-1965); credentials are
     * never part of it.
     *
     * @return array{request: array<int|string, mixed>, response: array{http_status: int, body: mixed}|null}|null
     */
    public function lastExchange(): ?array
    {
        return $this->lastExchange;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int|string, mixed>
     */
    private function request(string $method, string $endpoint, array $options): array
    {
        $uri = sprintf('api/%s.php', $endpoint);
        $this->logger->debug('Smaily API request', [
            'method' => $method,
            'endpoint' => $uri,
            'options' => $this->redact($options),
        ]);
        $this->lastExchange = [
            'request' => (array)($options[RequestOptions::JSON] ?? $options[RequestOptions::QUERY] ?? []),
            'response' => null,
        ];

        try {
            $response = $this->getHttpClient()->request($method, $uri, $options);
        } catch (BadResponseException $exception) {
            $status = $exception->getResponse()->getStatusCode();
            $this->lastExchange['response'] = ExchangeResponse::of(
                $status,
                (string)$exception->getResponse()->getBody()
            );
            $this->logger->error('Smaily API HTTP error', [
                'method' => $method,
                'endpoint' => $uri,
                'status' => $status,
            ]);
            if ($this->isPlanBlocked($exception->getResponse())) {
                // Smaily answers this before it checks the credentials: they
                // are not refused, the package is (PRO-3579).
                $this->verifiedCredentials->planBlocked($this->subdomain, $this->username, $this->password);
                throw new PlanBlockedException(
                    (string)__(
                        'Smaily refused the request because this account\'s package does not include API access.'
                        . ' Upgrade the package in Smaily to connect — until then the credentials cannot be checked at all.'
                    ),
                    $status,
                    $exception
                );
            }
            if (in_array($status, [401, 403], true)) {
                // Wherever the refusal came from (the queue included), these
                // credentials are no longer "Connected" (PRO-3560).
                $this->verifiedCredentials->refuse($this->subdomain, $this->username, $this->password);
                throw new AuthenticationException((string)__('Smaily API credentials were rejected'), $status, $exception);
            }
            throw new TransportException(
                (string)__('Smaily API request failed with HTTP %1', $status),
                $status,
                $exception,
                $this->retryAfterSeconds($exception->getResponse())
            );
        } catch (GuzzleException $exception) {
            // Guzzle's message ends with the request URL, query included — the
            // contact lookup's email with it. Only the masked text goes on,
            // and the raw exception is not chained (PRO-3572).
            $error = TransportErrorMessage::of($exception);
            $this->logger->error('Smaily API transport error', [
                'method' => $method,
                'endpoint' => $uri,
                'error' => $error,
            ]);
            throw new TransportException((string)__('Smaily API request failed: %1', $error));
        }

        $body = (string)$response->getBody();
        $this->lastExchange['response'] = ExchangeResponse::of($response->getStatusCode(), $body);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new TransportException((string)__('Smaily API returned a malformed response body'));
        }

        // Summarized on purpose: full bodies would put contact PII in logs.
        $this->logger->debug('Smaily API response', [
            'endpoint' => $uri,
            'code' => $decoded['code'] ?? null,
            'rows' => array_is_list($decoded) ? count($decoded) : 1,
        ]);

        if (isset($decoded['code']) && (int)$decoded['code'] !== ApiException::CODE_SUCCESS) {
            throw new ApiException(
                (string)__(
                    'Smaily API returned code %1: %2',
                    (int)$decoded['code'],
                    (string)($decoded['message'] ?? 'unknown error')
                ),
                (int)$decoded['code'],
                $decoded
            );
        }

        return $decoded;
    }

    /**
     * Smaily code 227 in an error response body: "A paid package is required".
     */
    private function isPlanBlocked(ResponseInterface $response): bool
    {
        $decoded = json_decode((string)$response->getBody(), true);

        return is_array($decoded)
            && isset($decoded['code'])
            && (int)$decoded['code'] === PlanBlockedException::SMAILY_CODE;
    }

    /**
     * The Retry-After header as whole seconds, or null when absent or
     * expressed as an HTTP-date (Smaily sends the delta-seconds form; a date
     * falls back to the queue's own backoff rather than being mis-parsed).
     */
    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('Retry-After');
        if (!is_numeric($header)) {
            return null;
        }

        $seconds = (int)$header;

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * @throws InvalidSubdomainException when the subdomain is not one plain label
     */
    private function getHttpClient(): HttpClient
    {
        if (!SmailyUrl::isPlainSubdomain($this->subdomain)) {
            throw new InvalidSubdomainException();
        }
        if ($this->httpClient === null) {
            $this->httpClient = $this->httpClientFactory->create([
                'base_uri' => SmailyUrl::forSubdomain($this->subdomain) . '/',
                RequestOptions::AUTH => [$this->username, $this->password],
                RequestOptions::TIMEOUT => self::TIMEOUT_SECONDS,
                RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                RequestOptions::HEADERS => [
                    'User-Agent' => ModuleInfo::USER_AGENT,
                    'Accept' => 'application/json',
                ],
            ]);
        }

        return $this->httpClient;
    }

    /**
     * Strip credentials and summarize PII-bearing payloads for debug logs.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function redact(array $options): array
    {
        unset($options[RequestOptions::AUTH]);

        if (isset($options[RequestOptions::JSON]) && is_array($options[RequestOptions::JSON])) {
            $body = $options[RequestOptions::JSON];
            $options[RequestOptions::JSON] = [
                'items' => array_is_list($body) ? count($body) : 1,
                'keys' => array_slice(array_keys(array_is_list($body) ? ($body[0] ?? []) : $body), 0, 20),
            ];
        }
        if (isset($options[RequestOptions::QUERY]['email'])) {
            $options[RequestOptions::QUERY]['email'] = '[redacted]';
        }

        return $options;
    }
}
