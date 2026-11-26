<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\Queue\EventQueue;

/**
 * The engine's limit on privacy requests (CON-432): 10 requests in 60
 * seconds per engine store, one limit for every GDPR request — the
 * customer export, delete and opt-out and the `/api/v1/gdpr/...` requests
 * (contract v1.37.1, Rate limiting). Kept per engine store (its tenant id),
 * so every connection to one store shares it, and in the application cache,
 * so the queue's cron run and `bin/magento smaily:gdpr` share it too.
 *
 * Engine\Client takes a turn before each privacy request and never waits: a
 * request the limit does not allow now is not sent, and the caller learns
 * how long to wait. A 429 the engine answers anyway (a cache flush forgets
 * the requests made, the engine counts on its own) pauses the store for
 * as long as the engine asks.
 */
class PrivacyRequestBudget
{
    public const LIMIT = 10;
    public const WINDOW_SECONDS = 60;

    /**
     * The longest pause a 429 sets: the ceiling of the queue's retry ladder
     * (EventQueue::BACKOFF_SECONDS, its last rung), so a wild answer
     * cannot stop the privacy requests for days. A constant expression
     * cannot count the ladder, so it names the last rung's index;
     * PrivacyRequestBudgetTest checks it is the last.
     */
    public const MAX_PAUSE_SECONDS = EventQueue::BACKOFF_SECONDS[4];

    private const CACHE_PREFIX = 'smaily_connect_privacy_requests_';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Seconds until the store may send its next privacy request; 0 when it
     * may send one now.
     */
    public function waitSeconds(string $store): int
    {
        $now = $this->now();

        return $this->wait($this->load($store, $now), $now);
    }

    /**
     * Take a turn for one privacy request the store sends now: the seconds
     * until it may send one, or 0 once the request is counted.
     */
    public function take(string $store): int
    {
        $now = $this->now();
        $state = $this->load($store, $now);
        $wait = $this->wait($state, $now);
        if ($wait > 0) {
            return $wait;
        }
        $state['sent'][] = $now;
        $this->save($store, $state, $now);

        return 0;
    }

    /**
     * The engine answered 429: no privacy request of the store goes for
     * $seconds (at least 1, at most MAX_PAUSE_SECONDS).
     *
     * @return int the pause set, in seconds
     */
    public function pause(string $store, int $seconds): int
    {
        $seconds = min(max(1, $seconds), self::MAX_PAUSE_SECONDS);
        $now = $this->now();
        $state = $this->load($store, $now);
        $state['until'] = max($state['until'], $now + $seconds);
        $this->save($store, $state, $now);

        return $seconds;
    }

    /**
     * @param array{sent: list<int>, until: int} $state
     */
    private function wait(array $state, int $now): int
    {
        $wait = max(0, $state['until'] - $now);
        $count = count($state['sent']);
        if ($count >= self::LIMIT) {
            // The request that leaves the window first makes room.
            $wait = max($wait, $state['sent'][$count - self::LIMIT] + self::WINDOW_SECONDS - $now);
        }

        return $wait;
    }

    /**
     * The store's requests inside the window, oldest first, and the end of
     * its pause.
     *
     * @return array{sent: list<int>, until: int}
     */
    private function load(string $store, int $now): array
    {
        $stored = json_decode((string)$this->cache->load($this->key($store)), true);
        $sent = is_array($stored) && is_array($stored['sent'] ?? null) ? $stored['sent'] : [];
        $sent = array_values(array_filter(
            array_map('intval', $sent),
            static fn (int $at): bool => $at > $now - self::WINDOW_SECONDS
        ));
        sort($sent);

        return ['sent' => $sent, 'until' => is_array($stored) ? (int)($stored['until'] ?? 0) : 0];
    }

    /**
     * @param array{sent: list<int>, until: int} $state
     */
    private function save(string $store, array $state, int $now): void
    {
        $this->cache->save(
            (string)json_encode($state),
            $this->key($store),
            [],
            max(self::WINDOW_SECONDS, $state['until'] - $now) + self::WINDOW_SECONDS
        );
    }

    private function key(string $store): string
    {
        return self::CACHE_PREFIX . sha1($store);
    }

    private function now(): int
    {
        return (int)$this->dateTime->gmtTimestamp();
    }
}
