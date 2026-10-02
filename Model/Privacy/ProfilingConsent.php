<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Privacy;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\Client\Exception\ApiException;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;

/**
 * Shopper profiling consent (opt-out model, default on). Profile unless the
 * shopper opted out of profiling OR unsubscribed from marketing: leaving
 * marketing also stops profiling (PRO-3578, Woo F3-31; Erkki 2026-10-02).
 *
 * A choice goes to the Smaily contact (smaily_rec_profiling 0/1 +
 * smaily_rec_profiling_ts), into the store's own durable record
 * (ProfilingOptOuts) and onto the marketing event queue for the engine's
 * opt-out endpoint (contract §10), where it gets the normal retry ladder
 * (PRO-3578).
 *
 * A read resolves the Smaily contact against the store's record, and the
 * newest choice wins (Woo PRO-3191/3192/3434): only an opt-in on the contact
 * stamped after the store's opt-out lifts it. Reads are cached for a day; when
 * Smaily cannot be read the store's record decides, and a shopper it holds
 * no opt-out for is profiled (fail open, the Woo posture).
 */
class ProfilingConsent
{
    private const CACHE_PREFIX = 'smaily_profiling_';
    private const CACHE_TTL_SECONDS = 86400;
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * How far in the future a contact's profiling timestamp may lie and still
     * count: only drift between one store's servers, which stamp and compare
     * with the same clock (Woo PRO-3434).
     */
    private const CLOCK_SKEW_ALLOWANCE_SECONDS = 300;

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

        $optOutMoment = $this->optOuts->moment($email);
        $found = true;
        try {
            $contact = $this->smailyClientProvider->forStore($storeId)
                ->get(SmailyClient::ENDPOINT_CONTACT, ['email' => $email]);
            $fields = isset($contact[0]) && is_array($contact[0]) ? $contact[0] : $contact;
        } catch (ApiException $exception) {
            if ($exception->getSmailyCode() !== ApiException::CODE_EMAIL_NOT_FOUND) {
                return $this->fallback($email, $cacheKey, $optOutMoment, $exception);
            }
            // Unknown contact: no answer on Smaily's side, not an error.
            $found = false;
            $fields = [];
        } catch (SmailyClientException $exception) {
            return $this->fallback($email, $cacheKey, $optOutMoment, $exception);
        }

        $allowed = (string)($fields['is_unsubscribed'] ?? '') !== '1'
            && (string)($fields['smaily_rec_profiling'] ?? '') !== '0';

        if ($allowed && $optOutMoment !== null) {
            if ($this->isNewerOptIn($fields, $optOutMoment)) {
                // The newest choice is an opt-in made after the store's
                // opt-out: it wins here and at the engine.
                $this->optOuts->forget($email);
                $this->queueForEngine($email, false, '');
            } else {
                // PRO-3191/3192 parity: a contact without an answer, or with
                // an older one, does not lift the store's opt-out. Carried to
                // the contact so Smaily agrees — never to a contact Smaily
                // does not have, since the write would create one.
                $allowed = false;
                if ($found) {
                    $moment = $this->dateTime->gmtTimestamp();
                    $this->writeToContact($email, false, $storeId, gmdate(self::TIMESTAMP_FORMAT, $moment));
                    $this->optOuts->record($email, $moment);
                }
            }
        }

        $this->cache->save($allowed ? '1' : '0', $cacheKey, [], self::CACHE_TTL_SECONDS);

        return $allowed;
    }

    /**
     * Smaily could not be read: the store's own record decides, and a
     * shopper the store holds no opt-out for is profiled (fail open, the
     * model's accepted residual risk — Woo F3-31).
     */
    private function fallback(string $email, string $cacheKey, ?int $optOutMoment, \Throwable $exception): bool
    {
        $this->logger->debug('Profiling consent read failed', ['error' => $exception->getMessage()]);
        $allowed = $optOutMoment === null;
        $this->cache->save($allowed ? '1' : '0', $cacheKey, [], self::CACHE_TTL_SECONDS);

        return $allowed;
    }

    /**
     * Is the contact's answer an opt-in made AFTER the store's opt-out?
     * Only a timestamp in exactly the form this module writes counts, and
     * not one beyond the clock-skew allowance in the future (Woo PRO-3434):
     * anything else reads as older, so the opt-out holds.
     *
     * @param array<int|string, mixed> $fields
     */
    private function isNewerOptIn(array $fields, int $optOutMoment): bool
    {
        if ((string)($fields['smaily_rec_profiling'] ?? '') !== '1') {
            return false;
        }

        $stamp = (string)($fields['smaily_rec_profiling_ts'] ?? '');
        $given = \DateTimeImmutable::createFromFormat('!' . self::TIMESTAMP_FORMAT, $stamp, new \DateTimeZone('UTC'));
        if ($given === false || $given->format(self::TIMESTAMP_FORMAT) !== $stamp) {
            return false;
        }

        return $given->getTimestamp() <= $this->dateTime->gmtTimestamp() + self::CLOCK_SKEW_ALLOWANCE_SECONDS
            && $given->getTimestamp() > $optOutMoment;
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
        $timestamp = gmdate(self::TIMESTAMP_FORMAT, $moment);

        $this->writeToContact($email, $allowed, $storeId, $timestamp);
        if ($allowed) {
            $this->optOuts->forget($email);
        } else {
            $this->optOuts->record($email, $moment);
        }
        $this->queueForEngine($email, !$allowed, $timestamp);

        $this->cache->save($allowed ? '1' : '0', self::CACHE_PREFIX . sha1($email), [], self::CACHE_TTL_SECONDS);
    }

    /**
     * The shopper unsubscribed from marketing, which also stops profiling:
     * kept as the store's opt-out at this moment, so an older opt-in on the
     * contact cannot lift it, and queued for the engine. Nothing is written
     * to the Smaily contact — the unsubscribe itself reaches Smaily.
     */
    public function optOutOnUnsubscribe(string $email): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }

        $moment = $this->dateTime->gmtTimestamp();
        $this->optOuts->record($email, $moment);
        $this->queueForEngine($email, true, gmdate(self::TIMESTAMP_FORMAT, $moment));
        $this->cache->save('0', self::CACHE_PREFIX . sha1($email), [], self::CACHE_TTL_SECONDS);
    }

    /**
     * The choice on the Smaily contact. A failed write is logged, never
     * fatal: the store's record and the engine row still hold the choice.
     */
    private function writeToContact(string $email, bool $allowed, int|string|null $storeId, string $timestamp): void
    {
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
