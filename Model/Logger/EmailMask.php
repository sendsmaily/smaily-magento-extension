<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Logger;

/**
 * Masks every email address in a text to its first characters
 * ("e***@g***.com"): enough to recognize a contact, without the address.
 *
 * An address is found written plainly, URL-encoded (`%40`, also
 * double-encoded `%2540`) and JSON-escaped (`@`, also escaped twice),
 * which is how an address reaches an error message that quotes a URL or a
 * JSON body. The separator is kept as it was written, so the surrounding URL
 * or JSON stays readable as such.
 */
class EmailMask
{
    private const PATTERN = '/(?<local>[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.-]+)'
        . '(?<at>@|%(?:25)*40|\\\\+u0040)'
        . '(?<domain>[A-Za-z0-9.-]+\.[A-Za-z]{2,})/i';

    /**
     * The text with every address in it masked.
     *
     * @param string $text
     * @return string
     */
    public static function apply(string $text): string
    {
        return (string)preg_replace_callback(
            self::PATTERN,
            static function (array $match): string {
                $labels = explode('.', $match['domain']);
                $tld = array_pop($labels);

                return mb_substr($match['local'], 0, 1) . '***' . $match['at']
                    . mb_substr((string)($labels[0] ?? ''), 0, 1) . '***.' . $tld;
            },
            $text
        );
    }
}
