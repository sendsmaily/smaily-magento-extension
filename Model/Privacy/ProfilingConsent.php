<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Privacy;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;

/**
 * Shopper profiling consent (opt-out model, default on) — a separate lawful
 * axis from marketing consent (a marketing unsubscribe is not an Art. 21
 * profiling objection).
 *
 * A choice goes to the Smaily contact (smaily_rec_profiling 0/1 +
 * smaily_rec_profiling_ts), into the store's own durable record
 * (ProfilingOptOuts) and onto the marketing event queue for the engine's
 * opt-out endpoint (contract §10), where it gets the normal retry ladder
 * (PRO-3578). Reads are cached for a day and FAIL OPEN on transport errors
 * (matching the Woo ProfilingConsent posture).
 */
class ProfilingConsent
{
    private const CACHE_PREFIX = 'smaily_profiling_';
    private const CACHE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly SmailyClientProvider $smailyClientProvider,
        private readonly Settings $engineSettings,
        private readonly EventQueue $eventQueue,
        private readonly ProfilingOptOuts $optOuts,
        private readonly CacheInterface $cache,
        private readonly DateTime $dateTime,
        private readonly Logger $logger
    ) {
    }

    /**
     * Whether profiling is allowed for the contact (default true).
     */
    public function isAllowed(string $email, int|string|null $storeId = null): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return true;
        }

        $cacheKey = self::CACHE_PREFIX . sha1($email);
        $cached = $this->cache->load($cacheKey);
        if ($cached !== false) {
            return $cached === '1';
        }

        $allowed = true;
        try {
            $contact = $this->smailyClientProvider->forStore($storeId)
                ->get(SmailyClient::ENDPOINT_CONTACT, ['email' => $email]);
            $value = $contact['smaily_rec_profiling'] ?? ($contact[0]['smaily_rec_profiling'] ?? null);
            if ($value !== null && (string)$value === '0') {
                $allowed = false;
            }
        } catch (\Smaily\Connect\Model\Client\Exception\ApiException $exception) {
            if ($exception->getSmailyCode() !== \Smaily\Connect\Model\Client\Exception\ApiException::CODE_EMAIL_NOT_FOUND) {
                $this->logger->debug('Profiling consent read failed', ['error' => $exception->getMessage()]);
            }
            // Unknown contact = default allowed; not an error condition.
        } catch (SmailyClientException $exception) {
            // Fail open: an unreachable API must not break the storefront.
            $this->logger->debug('Profiling consent read failed', ['error' => $exception->getMessage()]);
        }

        $this->cache->save($allowed ? '1' : '0', $cacheKey, [], self::CACHE_TTL_SECONDS);

        return $allowed;
    }

    /**
     * Record the shopper's choice: Smaily contact fields, the store's own
     * record and the engine, through the queue.
     */
    public function setAllowed(string $email, bool $allowed, int|string|null $storeId = null): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }

        $moment = $this->dateTime->gmtTimestamp();
        $timestamp = gmdate('Y-m-d\TH:i:s\Z', $moment);

        try {
            $this->smailyClientProvider->forStore($storeId)->post(SmailyClient::ENDPOINT_CONTACT, [[
                'email' => $email,
                'smaily_rec_profiling' => $allowed ? 1 : 0,
                'smaily_rec_profiling_ts' => $timestamp,
            ]]);
        } catch (SmailyClientException $exception) {
            $this->logger->error('Profiling consent write to Smaily failed', [
                'error' => $exception->getMessage(),
            ]);
        }

        if ($allowed) {
            $this->optOuts->forget($email);
        } else {
            $this->optOuts->record($email, $moment);
        }
        $this->queueForEngine($email, !$allowed, $timestamp);

        $this->cache->save($allowed ? '1' : '0', self::CACHE_PREFIX . sha1($email), [], self::CACHE_TTL_SECONDS);
    }

    /**
     * The engine hears a choice through the queue — retried and logged like
     * every other delivery. isConnected() is the gate for paths that only
     * enqueue; the handler asks the sending gate.
     */
    private function queueForEngine(string $email, bool $optOut, string $timestamp): void
    {
        if (!$this->engineSettings->isConnected()) {
            return;
        }

        $payload = ['email' => $email, 'opt_out' => $optOut];
        if ($optOut) {
            $payload['opted_out_at'] = $timestamp;
        }
        $this->eventQueue->enqueue(EventType::ENGINE_PROFILING_CONSENT, $payload, $email);
    }
}
