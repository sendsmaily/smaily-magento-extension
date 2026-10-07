<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

/**
 * The Smaily host for one account subdomain, built in exactly one place so
 * the API client's base URI and the admin's account link cannot drift apart.
 */
class SmailyUrl
{
    /** One DNS label: letters, digits and inner hyphens, at most 63 characters. */
    private const PLAIN_SUBDOMAIN_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i';

    /**
     * Whether a (normalised) subdomain is one plain label, so that
     * forSubdomain() names a host under sendsmaily.net and nothing else.
     */
    public static function isPlainSubdomain(string $subdomain): bool
    {
        return preg_match(self::PLAIN_SUBDOMAIN_PATTERN, $subdomain) === 1;
    }

    /**
     * https://{subdomain}.sendsmaily.net — no trailing slash; callers that
     * need one (a Guzzle base URI) add it.
     */
    public static function forSubdomain(string $subdomain): string
    {
        return 'https://' . $subdomain . '.sendsmaily.net';
    }
}
