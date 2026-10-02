<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\RateLimit;

use Magento\Framework\App\CacheInterface;

/**
 * A counter per cache key in the application cache, for the anonymous
 * storefront endpoints' limits (the browse relay, the guest cart email). The
 * caller puts the window in the key (for example the current minute), so a
 * new window is a new key; the TTL only lets an old key expire. A cache flush
 * resets every count.
 */
class FixedWindowCounter
{
    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * Counts one hit on $key when the key has fewer than $limit hits; a
     * refused hit is not counted.
     */
    public function allow(string $key, int $limit, int $ttlSeconds): bool
    {
        if ($this->isExhausted($key, $limit)) {
            return false;
        }
        $this->hit($key, $ttlSeconds);

        return true;
    }

    /**
     * Whether $key already has $limit hits, without counting one.
     */
    public function isExhausted(string $key, int $limit): bool
    {
        return (int)$this->cache->load($key) >= $limit;
    }

    /**
     * Counts one hit on $key without a limit, for a count that grows only
     * after the work it limits has succeeded.
     */
    public function hit(string $key, int $ttlSeconds): void
    {
        $count = (int)$this->cache->load($key);
        $this->cache->save((string)($count + 1), $key, [], $ttlSeconds);
    }
}
