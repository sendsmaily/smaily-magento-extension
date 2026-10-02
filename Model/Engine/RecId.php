<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

/**
 * The shape the engine accepts for `smaily_rec_id` (contract §5): a UUID,
 * validated with zod's `uuid()` — 8-4-4-4-12 hex digits, either case, with
 * no version or variant constraint. Deliberately not stricter than the
 * engine: a value the engine accepts must never be dropped here. The D
 * modifier keeps `$` from accepting a trailing newline the engine refuses.
 *
 * A malformed value makes the engine reject the whole order, not just the
 * field (PRO-3576), so the order payload omits a value that fails this
 * check. The storefront capture scripts apply the same pattern before they
 * write the cookie.
 */
class RecId
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
