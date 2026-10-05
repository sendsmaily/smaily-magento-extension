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
 * marketing also stops profiling (PRO-3578, Woo F3-31; Erkki 2026-10-02),
 * and subscribing again starts it again, unless the shopper also opted out
 * of profiling on its own (PRO-3594, Erkki 2026-10-02).
 *
 * A choice goes to the Smaily contact (smaily_rec_profiling 0/1 +
 * smaily_rec_profiling_ts) when Smaily has one — Smaily creates a contact
 * sent without a status as subscribed (PRO-3619) — into the store's own
 * durable record (ProfilingOptOuts) and onto the marketing event queue for
 * the engine's opt-out endpoint (contract §10), where it gets the normal
 * retry ladder (PRO-3578).
 *
 * A read resolves the Smaily contact against the store's record, and the
 * newest choice wins (Woo PRO-3191/3192/3434): only an opt-in on the contact
 * stamped after the store's opt-out lifts it. Reads are cached for a day, under
 * the opt-out record's keyed hash of the address (PRO-3575); when
 * Smaily cannot be read the store's record decides, and a shopper it holds
 * no opt-out for is profiled (fail open, the Woo posture).
 */
class ProfilingConsent
{
    private const CACHE_PREFIX = 'smaily_profiling_';
    private const CONTACT_CACHE_PREFIX = 'smaily_profiling_contact_';
    private const CACHE_TTL_SECONDS = 86400;
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * Cached when Smaily could not be read and the store holds no opt-out:
     * the gate profiles (fail open), but the preference is not known
     * (PRO-3591).
     */
    private const CACHED_GUESS = '?';

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

        $cached = $this->cache->load($this->cacheKey($email));
        if ($cached !== false) {
            return $cached === '1' || $cached === self::CACHED_GUESS;
        }

        $optOutMoment = $this->optOuts->moment($email);
        try {
            $fields = $this->readContact($email, $storeId);
        } catch (SmailyClientException $exception) {
            return $this->fallback($email, $optOutMoment, $exception);
        }
        // Unknown contact: no answer on Smaily's side, not an error.
        $found = $fields !== null;
        $fields ??= [];

        $allowed = (string)($fields['is_unsubscribed'] ?? '') !== '1'
            && (string)($fields['smaily_rec_profiling'] ?? '') !== '0';

        if (!$allowed && $optOutMoment === null) {
            // An opt-out made in Smaily (or an unsubscribe there) the store
            // did not know: kept as a mirror (moment 0, so any dated opt-in
            // is newer — Woo PRO-3192), with its origin, and carried to the
            // engine.
            $this->optOuts->record($email, 0, (string)($fields['smaily_rec_profiling'] ?? '') !== '0');
            $this->queueForEngine($email, true, gmdate(self::TIMESTAMP_FORMAT, $this->dateTime->gmtTimestamp()));
        } elseif ($allowed && $optOutMoment !== null) {
            if ($this->isNewerOptIn($fields, $optOutMoment)) {
                // The newest choice is an opt-in made after the store's
                // opt-out: it wins here and at the engine.
                $this->optOuts->forget($email);
                $this->queueForEngine($email, false, '');
            } else {
                // PRO-3191/3192 parity: a contact without an answer, or with
                // an older one, does not lift the store's opt-out. Carried to
                // the contact so Smaily agrees — never to a contact Smaily
                // does not have, since the write would create one, and never
                // for an opt-out by unsubscribing (PRO-3594): the unsubscribe
                // reaches Smaily on its own, and a profiling field written
                // for it would outlast the shopper subscribing again.
                $allowed = false;
                if ($found && !$this->optOuts->isByUnsubscribe($email)) {
                    $moment = $this->dateTime->gmtTimestamp();
                    $this->writeToContact($email, false, $storeId, gmdate(self::TIMESTAMP_FORMAT, $moment));
                    $this->optOuts->record($email, $moment);
                }
            }
        }

        $this->remember($email, $allowed ? '1' : '0');

        return $allowed;
    }

    /**
     * The preference as far as the store knows it, for display on My Account
     * (PRO-3591, Woo PRO-3189): the store's record or a successful Smaily
     * read. Null when Smaily could not be read and the store holds no
     * opt-out — the case where isAllowed() fails open. A cached guess is
     * checked with Smaily again first, so the page never shows a guess as
     * the shopper's answer.
     */
    public function knownPreference(string $email, int|string|null $storeId = null): ?bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        $cacheKey = $this->cacheKey($email);
        if ($this->cache->load($cacheKey) === self::CACHED_GUESS) {
            $this->cache->remove($cacheKey);
        }
        $allowed = $this->isAllowed($email, $storeId);

        return $this->cache->load($cacheKey) === self::CACHED_GUESS ? null : $allowed;
    }

    /**
     * Smaily could not be read: the store's own record decides, and a
     * shopper the store holds no opt-out for is profiled (fail open, the
     * model's accepted residual risk — Woo F3-31) — cached as a guess.
     */
    private function fallback(string $email, ?int $optOutMoment, \Throwable $exception): bool
    {
        $this->logger->debug('Profiling consent read failed', ['error' => $exception->getMessage()]);
        $allowed = $optOutMoment === null;
        $this->remember($email, $allowed ? self::CACHED_GUESS : '0');

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
     * Record the shopper's choice: Smaily contact fields — only on a contact
     * Smaily has, since the write would create one as a subscriber
     * (PRO-3619) — the store's own record and the engine, through the queue.
     */
    public function setAllowed(string $email, bool $allowed, int|string|null $storeId = null): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }

        $moment = $this->dateTime->gmtTimestamp();
        $timestamp = gmdate(self::TIMESTAMP_FORMAT, $moment);

        if ($this->hasContact($email, $storeId)) {
            $this->writeToContact($email, $allowed, $storeId, $timestamp);
        }
        if ($allowed) {
            $this->optOuts->forget($email);
        } else {
            $this->optOuts->record($email, $moment);
        }
        $this->queueForEngine($email, !$allowed, $timestamp);

        $this->remember($email, $allowed ? '1' : '0');
    }

    /**
     * The shopper unsubscribed from marketing, which also stops profiling:
     * kept as the store's opt-out by unsubscribing at this moment, so an
     * older opt-in on the contact cannot lift it, and queued for the engine.
     * A profiling opt-out the shopper made on its own stays as it is, so
     * subscribing again does not lift it. Nothing is written to the Smaily
     * contact — the unsubscribe itself reaches Smaily.
     */
    public function optOutOnUnsubscribe(string $email): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }

        $this->remember($email, '0');
        if ($this->optOuts->moment($email) !== null && !$this->optOuts->isByUnsubscribe($email)) {
            return;
        }

        $moment = $this->dateTime->gmtTimestamp();
        $this->optOuts->record($email, $moment, true);
        $this->queueForEngine($email, true, gmdate(self::TIMESTAMP_FORMAT, $moment));
    }

    /**
     * The shopper subscribed to marketing again (PRO-3594): an opt-out that
     * came only from unsubscribing is lifted and the engine hears the opt-in
     * through the queue. A profiling opt-out of the shopper's own stays.
     * Cached as allowed, so a read before Smaily hears the subscription does
     * not take the unsubscribe for a new one.
     */
    public function optInOnResubscribe(string $email): void
    {
        $email = strtolower(trim($email));
        if ($email === '' || !$this->optOuts->isByUnsubscribe($email)) {
            return;
        }

        $this->optOuts->forget($email);
        $this->queueForEngine($email, false, '');
        $this->remember($email, '1');
    }

    /**
     * The engine confirmed a customer or an order for these shoppers
     * (PRO-3760). The engine keeps an opt-out only for a shopper it holds,
     * and answers an opt-out for anyone else with "not found", so an opt-out
     * made before the engine knew the shopper did not stay there; customer
     * and order data carry no consent. The store's opt-out of each one who
     * opted out is queued again with its moment, through the same queue
     * row, so the newest choice still wins at delivery. Not for a shopper
     * whose opt-out already waits in the queue: many orders make one row.
     *
     * @param string[] $emails
     */
    public function resendOptOuts(array $emails): void
    {
        $emails = array_values(array_unique(array_filter(array_map(
            static fn (string $email): string => strtolower(trim($email)),
            $emails
        ))));
        if (!$emails) {
            return;
        }

        $moments = $this->optOuts->moments($emails);
        $waiting = $this->eventQueue->waitingProfilingOptOuts(array_keys($moments));
        foreach ($moments as $email => $moment) {
            if (in_array($email, $waiting, true)) {
                continue;
            }
            // A mirror of an opt-out read back from Smaily has no moment of
            // its own (0): it goes with the moment of sending, as it did.
            $this->queueForEngine(
                (string)$email,
                true,
                gmdate(self::TIMESTAMP_FORMAT, $moment > 0 ? $moment : $this->dateTime->gmtTimestamp())
            );
        }
    }

    private function cacheKey(string $email): string
    {
        return self::CACHE_PREFIX . $this->optOuts->addressKey($email);
    }

    /**
     * The Smaily contact's fields, or null when Smaily does not have the
     * contact. Whether it has one is kept for a day, so recording a choice
     * reuses the read the page view made (PRO-3619).
     *
     * @return array<int|string, mixed>|null
     * @throws SmailyClientException when Smaily cannot be read
     */
    private function readContact(string $email, int|string|null $storeId): ?array
    {
        try {
            $contact = $this->smailyClientProvider->forStore($storeId)
                ->get(SmailyClient::ENDPOINT_CONTACT, ['email' => $email]);
            $fields = isset($contact[0]) && is_array($contact[0]) ? $contact[0] : $contact;
        } catch (ApiException $exception) {
            if ($exception->getSmailyCode() !== ApiException::CODE_EMAIL_NOT_FOUND) {
                throw $exception;
            }
            $fields = null;
        }
        $this->cache->save(
            $fields === null ? '0' : '1',
            self::CONTACT_CACHE_PREFIX . $this->optOuts->addressKey($email),
            [],
            self::CACHE_TTL_SECONDS
        );

        return $fields;
    }

    /**
     * Whether Smaily has the contact: the answer of the last read, else a
     * read now. Not when Smaily cannot be read — a write could create it.
     */
    private function hasContact(string $email, int|string|null $storeId): bool
    {
        $known = $this->cache->load(self::CONTACT_CACHE_PREFIX . $this->optOuts->addressKey($email));
        if ($known !== false) {
            return $known === '1';
        }

        try {
            return $this->readContact($email, $storeId) !== null;
        } catch (SmailyClientException $exception) {
            $this->logger->error('Profiling consent write to Smaily skipped: the contact could not be read', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Cache the answer for a day: '1', '0' or the fail-open guess.
     */
    private function remember(string $email, string $answer): void
    {
        $this->cache->save($answer, $this->cacheKey($email), [], self::CACHE_TTL_SECONDS);
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
