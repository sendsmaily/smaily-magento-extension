<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

/**
 * A job's stored reason as the admin's import card and
 * `smaily:backfill:status` show it (CON-233): where the error that stopped
 * it happened (ErrorLocation) or one of the imports' own fixed reasons.
 * Anything else — an error's text that an earlier version stored, which can
 * quote a customer's data — shows as ''.
 */
class ShownReason
{
    /** The reasons the imports store themselves (ContactsProcessor, BackfillTick). */
    private const OWN_REASONS = '/^(?:Website \d+ has no stores|No backfill processor registered for "[\w:]+")$/';

    /**
     * $reason when it is one to show, else ''.
     */
    public static function of(string $reason): string
    {
        $shown = ErrorLocation::matches($reason)
            || $reason === Job::REASON_CONTACT_SYNC_INACTIVE
            || preg_match(self::OWN_REASONS, $reason) === 1;

        return $shown ? $reason : '';
    }
}
