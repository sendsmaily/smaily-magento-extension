<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue;

/**
 * Marketing event queue event types.
 */
class EventType
{
    public const CONTACT_SYNC = 'contact.sync';
    public const AUTOMATION_TRIGGER = 'automation.trigger';
    public const ENGINE_IDENTITY_MERGE = 'engine.identity_merge';
    public const ENGINE_PROFILING_CONSENT = 'engine.profiling_consent';
}
