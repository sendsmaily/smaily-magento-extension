<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Newsletter;

use Magento\Newsletter\Model\Subscriber;

/**
 * Whether a subscriber save changed the newsletter status, for every
 * newsletter_subscriber_save_after observer of ours (the contact sync and
 * the Campaign Intelligence observers).
 *
 * A status change is read from the status saved against the status loaded,
 * not from the subscriber's status-changed flag (PRO-4322, CON-436): code
 * that sets the status field directly leaves the flag unset, and
 * setStatus() sets it for the unchanged status when the loaded value is a
 * string, so the flag both lost changes and sent repeated saves again.
 */
class SubscriberStatus
{
    public static function changed(Subscriber $subscriber): bool
    {
        return $subscriber->dataHasChangedFor('subscriber_status');
    }
}
