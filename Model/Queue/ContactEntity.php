<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue;

use Smaily\Connect\Model\Privacy\AddressKey;

/**
 * The entity a contact-sync or automation row of the marketing queue is
 * stored and matched under (PRO-3767). An address that fits the queue's
 * entity column is the entity itself, so the Log's Entity filter finds it.
 * A longer address would be cut silently, so it is the keyed hash the
 * profiling-consent rows use (AddressKey, PRO-3765): 64 hex characters,
 * the same for every row of the contact. Every place that writes or matches
 * those rows' entity goes through here.
 */
class ContactEntity
{
    /** The width of smaily_event_queue.entity_id (etc/db_schema.xml). */
    public const MAX_LENGTH = 64;

    private const HASH_PATTERN = '/^[0-9a-f]{64}$/';

    public function __construct(
        private readonly AddressKey $addressKey
    ) {
    }

    /**
     * The entity of a contact's rows.
     */
    public function of(string $email): string
    {
        return mb_strlen($email) <= self::MAX_LENGTH ? $email : $this->addressKey->of($email);
    }

    /**
     * Every entity a row of this contact may carry: the keyed hash (a
     * profiling-consent row, PRO-3765, or a long address's contact row) and
     * the address as given (a contact row, or a consent row queued before
     * PRO-3765).
     *
     * @param string|null $key the address's keyed hash, when the caller holds it
     * @return string[]
     */
    public function forms(string $email, ?string $key = null): array
    {
        return [$key ?? $this->addressKey->of($email), $email];
    }

    /**
     * Whether a stored entity is a keyed hash rather than an address (an
     * address always holds an "@").
     */
    public static function isHash(string $entityId): bool
    {
        return preg_match(self::HASH_PATTERN, $entityId) === 1;
    }
}
