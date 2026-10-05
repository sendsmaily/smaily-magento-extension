<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Log;

use Smaily\Connect\Model\Queue\EventType;

/**
 * The Entity the Log shows for a queue row (PRO-3765). A profiling-consent
 * row stores the opt-out record's keyed hash of the shopper's address, not
 * the address: 64 hex characters read as noise, so its first 12 stand in,
 * enough to tell one shopper's rows from another's — and, being part of the
 * stored value, what the grid's Entity filter finds. Details shows the
 * address in the payload. Every other entity, a consent row queued before
 * with the plain address included, shows as stored.
 */
class EntityLabel
{
    private const SHOWN_HASH_LENGTH = 12;

    /**
     * The Entity as the Log shows it. Static so the grid column, the
     * Details panel and the Dashboard share one rule without a dependency.
     */
    public static function forDisplay(string $type, string $entityId): string
    {
        if ($type !== EventType::ENGINE_PROFILING_CONSENT || preg_match('/^[0-9a-f]{64}$/', $entityId) !== 1) {
            return $entityId;
        }

        return substr($entityId, 0, self::SHOWN_HASH_LENGTH) . '…';
    }
}
