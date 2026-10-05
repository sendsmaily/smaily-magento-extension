<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue;

use Smaily\Connect\Model\Privacy\ProfilingOptOuts;

/**
 * The entity a contact-sync or automation row of the marketing queue is
 * stored and matched under (PRO-3767). An address that fits the queue's
 * entity column is the entity itself, so the Log's Entity filter finds it.
 * A longer address would be cut silently, so it is the keyed hash the
 * profiling-consent rows use (ProfilingOptOuts::addressKey(), PRO-3765):
 * 64 hex characters, the same for every row of the contact. Every place
 * that writes or matches those rows' entity goes through here.
 */
class ContactEntity
{
    /** The width of smaily_event_queue.entity_id (etc/db_schema.xml). */
    public const MAX_LENGTH = 64;

    public function __construct(
        private readonly ProfilingOptOuts $optOuts
    ) {
    }

    /**
     * The entity of a contact's rows.
     */
    public function of(string $email): string
    {
        return mb_strlen($email) <= self::MAX_LENGTH
            ? $email
            : $this->optOuts->addressKey(strtolower(trim($email)));
    }
}
