<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Log;

use Magento\Framework\Phrase;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Privacy\Erasure;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Log\Collection;

/**
 * Decides whether one log row may be sent again (PRO-2454) — the single
 * server-owned answer, used by the Log's read model (hide the action and
 * explain why), by the "Send again" route (refuse the request) and by the
 * mass retry (skip the row and report it skipped). Shaped after the Woo
 * plugin's TransactionalRetryGuard: a reason code, with the merchant
 * sentence for it owned right beside it.
 *
 * Three of the refusals are the three ways sending again would be wrong:
 * the reminder was withdrawn on purpose, a later message of the same kind
 * already reached the contact, or the contact's data is gone. Everything
 * else is safe — an engine ingest row is an idempotent upsert, and a
 * contact sync or an identity merge repeats a state, not a message. The
 * fourth is the plain one: only a failed row is sent again at all. A
 * nightly catalog manifest row is never sent again: its list was the store
 * as it was that night, and the next night sends a new one (PRO-3854).
 */
class ResendGuard
{
    /** Withdrawn because the shopper bought (EventQueue::CANCELLED_RESPONSE). */
    public const REASON_WITHDRAWN = 'withdrawn';

    /** A later message of the same kind already reached this contact. */
    public const REASON_SUPERSEDED = 'superseded';

    /** An Art. 17 erasure took the recipient (Erasure::PLACEHOLDER). */
    public const REASON_ERASED = 'erased';

    /** Nothing went wrong with this row — there is nothing to send again. */
    public const REASON_NOT_FAILED = 'not_failed';

    /**
     * A nightly catalog manifest (PRO-3854): the list is built afresh right
     * before each night's send, so a row's list is never sent again.
     */
    public const REASON_NIGHTLY = 'nightly';

    public function __construct(
        private readonly EventQueue $eventQueue
    ) {
    }

    /**
     * Why this row may not be sent again, or '' when it may.
     *
     * @param array<string, mixed> $row
     */
    public function refusalReason(string $source, int $id, array $row): string
    {
        return $this->refusalReasons($source, [$id => $row])[$id] ?? '';
    }

    /**
     * The same question for a whole page or selection of one queue's rows:
     * one supersede lookup for all of them instead of one per row.
     *
     * @param array<int, array<string, mixed>> $rows row by row id
     * @return array<int, string> reason by row id, refused rows only
     */
    public function refusalReasons(string $source, array $rows): array
    {
        $refused = [];
        $notFailed = [];
        $automation = [];

        foreach ($rows as $id => $row) {
            $reason = $this->permanentReason($row);
            if ($source === Collection::SOURCE_INTELLIGENCE
                && (string)($row['type'] ?? '') === Client::DOMAIN_CATALOG_MANIFEST
            ) {
                $reason = self::REASON_NIGHTLY;
            }
            if ($reason !== '') {
                $refused[$id] = $reason;
                continue;
            }
            if ((string)($row['status'] ?? '') !== Event::STATUS_FAILED) {
                $notFailed[$id] = self::REASON_NOT_FAILED;
            }
            $entityId = (string)($row['entity_id'] ?? '');
            if ($source === Collection::SOURCE_SMAILY
                && (string)($row['type'] ?? '') === EventType::AUTOMATION_TRIGGER
                && $entityId !== ''
            ) {
                $automation[$id] = $entityId;
            }
        }

        foreach ($this->eventQueue->laterDeliveredOfSameTrigger($automation) as $id) {
            $refused[$id] = self::REASON_SUPERSEDED;
        }

        // A row that must never be sent again is named as such even where it
        // never failed: the drawer explains a withdrawn reminder either way.
        return $refused + $notFailed;
    }

    /**
     * The merchant-readable sentence for a refusal. The drawer shows it in
     * place of the retry line; the route behind "Send again" shows it when
     * it turns a stale page's request down.
     */
    public function message(string $reason): Phrase
    {
        return match ($reason) {
            self::REASON_WITHDRAWN => __(
                'This reminder was withdrawn because the shopper completed the purchase; '
                . 'it cannot be re-sent.'
            ),
            self::REASON_SUPERSEDED => __(
                'A later message of this kind already reached this contact; '
                . 'sending again would deliver it twice.'
            ),
            self::REASON_ERASED => __('This contact\'s data was erased; the event cannot be re-sent.'),
            self::REASON_NOT_FAILED => __('Only a failed event can be sent again.'),
            self::REASON_NIGHTLY => __(
                'The product list is built afresh each night, so this one is not sent again.'
            ),
            default => new Phrase(''),
        };
    }

    /**
     * The refusals that hold whatever the row's status is, read off the row
     * itself. A raw queue row carries the stored `last_response` marker, a
     * grid row the status derived from it, and both name the same withdrawn
     * row.
     *
     * @param array<string, mixed> $row
     */
    private function permanentReason(array $row): string
    {
        if ((string)($row['status'] ?? '') === Collection::STATUS_WITHDRAWN
            || (string)($row['last_response'] ?? '') === EventQueue::CANCELLED_RESPONSE
        ) {
            return self::REASON_WITHDRAWN;
        }

        return (string)($row['entity_id'] ?? '') === Erasure::PLACEHOLDER ? self::REASON_ERASED : '';
    }
}
