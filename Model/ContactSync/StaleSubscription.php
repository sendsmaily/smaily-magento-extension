<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ContactSync;

use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Log\Resend;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;

/**
 * Finds the contact syncs that would put a stale subscription into Smaily
 * (CON-394, CON-432).
 *
 * A row carries the status the store saw when it was queued, and rows do
 * not always go in the order they were queued: a row that failed waits for
 * its retry while a later row of the same contact is delivered. A row that
 * subscribes (`is_unsubscribed` 0) is stale once a later row of the same
 * contact that carries a status of its own, 0 or 1, was delivered: the later
 * row is the contact's newer choice, and the older one would undo it.
 *
 * - A row that unsubscribes is never stale: sent late, it can only narrow
 *   who is subscribed, and the shopper subscribes again in the store.
 * - A later row that is not delivered (waiting, failed, skipped) makes
 *   nothing stale; a row without a status (a profile save, an order, the
 *   purchase marker) is no choice of the contact's.
 * - The same contact is the same entity (Queue\ContactEntity) in the same
 *   Smaily account: the subdomain the row's store view sends through
 *   (SmailyClientProvider::forStore()). Two store views or websites that
 *   share an account share the contact; the same address in two accounts
 *   is two contacts.
 * - A row's place is the change it carries: a "Send again" row stands where
 *   the row it sends again stood (Log\Resend's record), and the handler
 *   posts a batch in that order (inChangeOrder()), so a "Send again" of an
 *   older subscription never goes after a newer change in one request.
 *
 * The one answer for the queue's handler, which closes a stale row as
 * skipped at every attempt, and for the Log's "Send again" and "Retry"
 * (Log\ResendGuard).
 */
class StaleSubscription
{
    public function __construct(
        private readonly EventQueue $eventQueue,
        private readonly Config $config
    ) {
    }

    /**
     * The rows of a batch in the order their changes were made.
     *
     * @param Event[] $events
     * @param array<int, array<int|string, mixed>> $payloads each row's decoded payload as stored, by row id
     * @return Event[]
     */
    public function inChangeOrder(array $events, array $payloads): array
    {
        $places = [];
        foreach ($events as $event) {
            $id = (int)$event->getId();
            $places[$id] = [Resend::placeOf($id, $payloads[$id] ?? []), $id];
        }
        usort(
            $events,
            static fn (Event $a, Event $b): int => $places[(int)$a->getId()] <=> $places[(int)$b->getId()]
        );

        return $events;
    }

    /**
     * Whether a stored contact-sync payload subscribes the contact.
     *
     * @param array<int|string, mixed> $payload
     */
    public static function subscribes(array $payload): bool
    {
        $contact = $payload['contact'] ?? null;

        return is_array($contact) && self::status($contact) === 0;
    }

    /**
     * The stale rows among these contact syncs: one read of the queue for
     * all of them. With the payload of every row, the read leaves out the
     * delivered rows at or below the smallest place among them
     * (EventQueue::contactSyncsOf()); without, it reads every delivered row
     * of the contacts.
     *
     * @param array<int, string> $entityIds entity id by contact-sync row id
     * @param array<int, array<int|string, mixed>> $payloads the rows' decoded payloads as stored, by row id
     * @return int[]
     */
    public function among(array $entityIds, array $payloads = []): array
    {
        $entityIds = array_filter($entityIds, static fn (string $entityId): bool => $entityId !== '');
        if (!$entityIds) {
            return [];
        }
        $deliveredAfter = 0;
        if (!array_diff_key($entityIds, $payloads)) {
            $deliveredAfter = min(array_map(
                static fn (int $id): int => Resend::placeOf($id, $payloads[$id]),
                array_keys($entityIds)
            ));
        }
        $byEntity = [];
        foreach ($this->eventQueue->contactSyncsOf($entityIds, array_keys($entityIds), $deliveredAfter) as $row) {
            $byEntity[$row['entity_id']][$row['id']] = $row;
        }

        $accounts = [];
        $account = function (array $payload) use (&$accounts): string {
            $storeId = (int)($payload['store_id'] ?? 0);

            return $accounts[$storeId] ??= $this->config->getSubdomain($storeId ?: null);
        };

        $stale = [];
        foreach ($entityIds as $id => $entityId) {
            $row = $byEntity[$entityId][$id] ?? null;
            if ($row === null || !self::subscribes($row['payload'])) {
                continue;
            }
            $place = Resend::placeOf($id, $row['payload']);
            foreach ($byEntity[$entityId] as $otherId => $other) {
                if ($otherId !== $id
                    && $other['delivered']
                    && Resend::placeOf($otherId, $other['payload']) > $place
                    && is_array($other['payload']['contact'] ?? null)
                    && self::status($other['payload']['contact']) !== null
                    && $account($other['payload']) === $account($row['payload'])
                ) {
                    $stale[] = $id;
                    break;
                }
            }
        }

        return $stale;
    }

    /**
     * The status a contact carries: 0 subscribed, 1 unsubscribed, null none.
     *
     * @param array<int|string, mixed> $contact
     */
    private static function status(array $contact): ?int
    {
        return isset($contact['is_unsubscribed']) ? (int)$contact['is_unsubscribed'] : null;
    }
}
