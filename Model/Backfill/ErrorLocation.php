<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

/**
 * Where an error was thrown, as "Class at File.php:42": what a stopped
 * import stores as its reason (CON-233) and what the log names for a row an
 * import skipped (SkippedRow, CON-227). Never the error's own text, which can
 * quote a customer's data — as the WooCommerce plugin records a failed
 * import.
 */
class ErrorLocation
{
    private const PATTERN = '/^[\w\\\\]+ at [^\s:]+:\d+$/';

    public static function of(\Throwable $exception): string
    {
        return sprintf(
            '%s at %s:%d',
            get_class($exception),
            basename($exception->getFile()),
            $exception->getLine()
        );
    }

    /**
     * Whether $text has the form of() writes.
     */
    public static function matches(string $text): bool
    {
        return preg_match(self::PATTERN, $text) === 1;
    }
}
