<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client;

/**
 * The text of a network-level HTTP failure, safe to log, store and show
 * (PRO-3572).
 *
 * Guzzle ends a network-failure message with the full request URL. The
 * Smaily contact lookup carries the address in the query string and the
 * engine's customer endpoints carry it in the path, so the raw message would
 * put a contact's email into var/log, a queue row's error column and the
 * admin. Every URL in the text keeps its scheme, host, port and path; the
 * query and fragment are dropped and an address-bearing path segment reads
 * {email}.
 */
class TransportErrorMessage
{
    private const URL_PATTERN = '~[a-z][a-z0-9+.-]*://[^\s()<>"\']+~i';

    public static function of(\Throwable $exception): string
    {
        return (string)preg_replace_callback(
            self::URL_PATTERN,
            static fn (array $match): string => self::maskUrl($match[0]),
            $exception->getMessage()
        );
    }

    private static function maskUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return '[url]';
        }

        $segments = explode('/', $parts['path'] ?? '');
        foreach ($segments as $index => $segment) {
            if (str_contains(rawurldecode($segment), '@')) {
                $segments[$index] = '{email}';
            }
        }

        return $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . implode('/', $segments);
    }
}
