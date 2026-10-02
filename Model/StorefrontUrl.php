<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

/**
 * The storefront address of a store that sells on a separate (headless)
 * storefront (PRO-3660): the catalog sync and the RSS feed send each product
 * link with its scheme, host and port replaced by the storefront's. The
 * path and the query string stay. Image links are not product links and are
 * never passed through here.
 */
class StorefrontUrl
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * The value as it is stored — "https://host" or "https://host:port",
     * lowercase, no trailing slash — or '' for an empty value. Null when the
     * value is not an https address of a host alone: a path other than "/",
     * a query, a fragment or a user name refuses it.
     */
    public static function normalize(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
            || !in_array($parts['path'] ?? '', ['', '/'], true)
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || str_ends_with($value, '?')
            || str_ends_with($value, '#')
        ) {
            return null;
        }

        return 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * The product link with the store's storefront address in place of its
     * own scheme, host and port; unchanged when the store has no (valid)
     * storefront address or the link has no host.
     */
    public function apply(string $url, int|string|null $storeId): string
    {
        $storefront = self::normalize($this->config->getStorefrontUrl($storeId));
        if ($storefront === null || $storefront === '') {
            return $url;
        }

        $rewritten = preg_replace('#^[a-z][a-z0-9+.\-]*://[^/?\#]*#i', $storefront, $url, 1, $count);

        return $count === 1 && is_string($rewritten) ? $rewritten : $url;
    }
}
