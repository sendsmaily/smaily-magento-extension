<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

/**
 * Reduces user input ("https://demo.sendsmaily.net/...", "demo.sendsmaily.net"
 * or "demo") to the bare Smaily subdomain.
 */
class SubdomainNormalizer
{
    public function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $value = $host;
        }
        $value = (string)preg_replace('/\.sendsmaily\.net.*$/i', '', $value);

        return strtolower(trim($value, " \t\n\r\0\x0B/."));
    }
}
