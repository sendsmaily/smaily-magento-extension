<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Queue;

use Smaily\Connect\Model\Report\ConnectionHealth;

/**
 * One entry of the engine's `errors[]` as a row's failure reason:
 * `<field>: <error code>`, or `<field>: rejected by engine` when the entry
 * names no code — never the entry's message, which can name the customer
 * (CON-357). Shared by the ingest flush and the login's customer send.
 */
class ItemError
{
    /**
     * @param array<mixed> $error
     */
    public static function reason(array $error, string $defaultField): string
    {
        $code = $error['error'] ?? null;

        return sprintf(
            '%s: %s',
            (string)($error['field'] ?? $defaultField),
            is_string($code) && preg_match(ConnectionHealth::CODE_PATTERN, $code) === 1 ? $code : 'rejected by engine'
        );
    }
}
