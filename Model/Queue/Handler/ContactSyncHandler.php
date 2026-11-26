<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue\Handler;

use Smaily\Connect\Api\Queue\EventHandlerInterface;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Client\Exception\ApiException;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\ContactSync\StaleSubscription;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\Failure;
use Smaily\Connect\Model\Queue\PayloadDecoder;
use Smaily\Connect\Model\Queue\Skipped;

/**
 * Delivers queued contact.sync events as batched POST /api/contact.php
 * upserts, grouped by store view so per-language Smaily accounts
 * (multilingual mode A) hit the right account; a group Smaily refuses as
 * invalid data goes again one contact per request (PRO-3753). An
 * abandoned-cart purchase marker is posted only after a read finds the
 * contact in Smaily (PRO-3619). A row that would put a stale subscription
 * into Smaily is closed as skipped, at every attempt and before anything is
 * sent, so a retry never undoes a newer choice of the contact's that was
 * already delivered (ContactSync\StaleSubscription, CON-394).
 *
 * Event payload shape: {store_id: int, contact: {email, ...}}.
 */
class ContactSyncHandler implements EventHandlerInterface
{
    public const SKIPPED_NOT_A_CONTACT = 'Skipped: Smaily does not have this contact, and the purchase marker'
        . ' would create it as a subscriber. Nothing was sent.';

    public const SKIPPED_STALE_SUBSCRIPTION = 'Skipped: the shopper has since changed their newsletter subscription,'
        . ' and the newer choice already reached Smaily. Nothing was sent.';

    public function __construct(
        private readonly SmailyClientProvider $clientProvider,
        private readonly EventQueue $eventQueue,
        private readonly Logger $logger,
        private readonly StaleSubscription $staleSubscription,
        private readonly PayloadDecoder $payloadDecoder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handle(array $events): array
    {
        $results = [];
        $byStore = [];
        // Each payload decoded once, as stored: the "Send again" record
        // gives a row its place, and the contact is posted on its own.
        $payloads = [];
        foreach ($events as $event) {
            $payloads[(int)$event->getId()] = $this->payloadDecoder->decode($event->getPayload());
        }
        $subscribing = $this->subscribing($events, $payloads);
        $stale = array_flip($this->staleSubscription->among($subscribing, $payloads));
        foreach ($this->staleSubscription->inChangeOrder($events, $payloads) as $event) {
            if (isset($stale[(int)$event->getId()])) {
                $results[(int)$event->getId()] = new Skipped(self::SKIPPED_STALE_SUBSCRIPTION);
                continue;
            }
            $payload = $payloads[(int)$event->getId()];
            $contact = $payload['contact'] ?? null;
            if (!is_array($contact) || empty($contact['email'])) {
                // Terminal: a malformed payload never improves on retry.
                $results[(int)$event->getId()] = Failure::permanent('Malformed contact payload');
                continue;
            }
            $storeId = (int)($payload['store_id'] ?? 0);
            if (isset($contact[Trigger::ABANDONED_CART_PURCHASED_FIELD])) {
                $unsent = $this->unsentMarker((string)$contact['email'], $storeId);
                if ($unsent !== null) {
                    $results[(int)$event->getId()] = $unsent;
                    continue;
                }
            }
            $byStore[$storeId][] = ['event' => $event, 'contact' => $contact];
        }

        foreach ($byStore as $storeId => $rows) {
            $results += $this->deliver($storeId, $rows);
        }

        return $results;
    }

    /**
     * The rows of the batch that subscribe the contact, the only ones that
     * can be stale: no read of the queue for a batch without one.
     *
     * @param Event[] $events
     * @param array<int, array<int|string, mixed>> $payloads decoded payload by row id
     * @return array<int, string> entity id by row id
     */
    private function subscribing(array $events, array $payloads): array
    {
        $subscribing = [];
        foreach ($events as $event) {
            if (StaleSubscription::subscribes($payloads[(int)$event->getId()])) {
                $subscribing[(int)$event->getId()] = (string)$event->getEntityId();
            }
        }

        return $subscribing;
    }

    /**
     * Post one store's contacts in one request, and each row's result. Smaily
     * answers one code per request, never per contact, so when it refuses a
     * group of contacts as invalid data (203) the contacts go again one at a
     * time in the same run: the valid ones sync and only the refused one
     * fails, with Smaily's answer (PRO-3753; WooCommerce posts one contact
     * per request). Any other failure stays the group's.
     *
     * @param list<array{event: Event, contact: array<string, mixed>}> $rows
     * @return array<int, true|SmailyClientException>
     */
    private function deliver(int $storeId, array $rows, ?SmailyClient $client = null): array
    {
        try {
            $client ??= $this->clientProvider->forStore($storeId ?: null);
            $client->post(SmailyClient::ENDPOINT_CONTACT, array_column($rows, 'contact'));
            $error = null;
        } catch (SmailyClientException $exception) {
            if (count($rows) > 1 && $exception instanceof ApiException && $exception->isInvalidData()) {
                $this->logger->info('Contact sync batch refused as invalid data; sending each contact alone', [
                    'store_id' => $storeId,
                    'count' => count($rows),
                ]);
                $results = [];
                foreach ($rows as $row) {
                    $results += $this->deliver($storeId, [$row], $client);
                }

                return $results;
            }
            // Handed on whole: only the exception carries the HTTP status
            // and Retry-After the queue classifies on (Failure::of()).
            $error = $exception;
            $this->logger->info('Contact sync batch failed', [
                'store_id' => $storeId,
                'count' => count($rows),
                'error' => $exception->getSourceMessage(),
            ]);
        }

        $results = [];
        $exchange = $client?->lastExchange();
        foreach ($rows as $row) {
            $event = $row['event'];
            $results[(int)$event->getId()] = $error ?? true;
            if ($exchange !== null) {
                // The row's own part of the request body — never the other
                // contacts posted with it — and the reply to that request.
                $this->eventQueue->recordExchange($event, [$row['contact']], $exchange['response']);
            }
        }

        return $results;
    }

    /**
     * Why the abandoned-cart purchase marker may not go yet, or null when it
     * may: Smaily creates a contact sent without a status as subscribed, so
     * the marker goes only to a contact Smaily has (PRO-3619). One read per
     * marker row, here in the cron, never at checkout. A contact Smaily does
     * not have closes the row; a read that fails leaves it to the retry
     * ladder, and nothing is posted until the answer is known.
     */
    private function unsentMarker(string $email, int $storeId): Skipped|SmailyClientException|null
    {
        try {
            $this->clientProvider->forStore($storeId ?: null)
                ->get(SmailyClient::ENDPOINT_CONTACT, ['email' => $email]);
        } catch (ApiException $exception) {
            return $exception->getSmailyCode() === ApiException::CODE_EMAIL_NOT_FOUND
                ? new Skipped(self::SKIPPED_NOT_A_CONTACT)
                : $exception;
        } catch (SmailyClientException $exception) {
            return $exception;
        }

        return null;
    }
}
