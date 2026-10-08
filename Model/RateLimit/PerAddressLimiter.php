<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\RateLimit;

use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * The anonymous storefront endpoints' limit per connection address (the
 * browse relay, the recommendations route, the guest cart email): a
 * fixed-window counter keyed `<prefix><sha1 of the caller>_<window>`. The
 * address is the connection's own, read through Magento's RemoteAddress: a
 * forwarding header counts only where the store's own configuration names
 * it, with its trusted proxies.
 */
class PerAddressLimiter
{
    public function __construct(
        private readonly FixedWindowCounter $counter,
        private readonly DateTime $dateTime,
        private readonly RemoteAddress $remoteAddress
    ) {
    }

    /**
     * Counts one request from the caller's address in the current window of
     * $windowSeconds when it has had fewer than $limit; the key lives for two
     * windows. With $ipv6By64, an IPv6 caller counts by its /64 (callerKey()).
     */
    public function allow(string $prefix, int $limit, int $windowSeconds = 60, bool $ipv6By64 = false): bool
    {
        $ip = (string)$this->remoteAddress->getRemoteAddress();
        $caller = $ipv6By64 ? $this->callerKey($ip) : $ip;
        $window = intdiv($this->dateTime->gmtTimestamp(), $windowSeconds);

        return $this->counter->allow($prefix . sha1($caller) . '_' . $window, $limit, 2 * $windowSeconds);
    }

    /**
     * An IPv4 address as it is, an IPv6 address by its /64 — one subscriber
     * holds a whole /64 and can pick a fresh address in it for every request.
     * An IPv4-mapped IPv6 address (::ffff:a.b.c.d) is its IPv4 address: its
     * /64 is the same for every IPv4 caller.
     */
    private function callerKey(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }
        $packed = (string)inet_pton($ip);
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string)inet_ntop(substr($packed, 12));
        }

        return bin2hex(substr($packed, 0, 8)) . '/64';
    }
}
