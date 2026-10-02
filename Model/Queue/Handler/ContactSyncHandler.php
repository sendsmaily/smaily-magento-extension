<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue\Handler;

use Smaily\Connect\Api\Queue\EventHandlerInterface;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;

/**
 * Delivers queued contact.sync events as batched POST /api/contact.php
 * upserts, grouped by store view so per-language Smaily accounts
 * (multilingual mode A) hit the right account.
 *
 * Event payload shape: {store_id: int, contact: {email, ...}}.
 */
class ContactSyncHandler implements EventHandlerInterface
{
    public function __construct(
        private readonly SmailyClientProvider $clientProvider,
        private readonly EventQueue $eventQueue,
        private readonly Logger $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handle(array $events): array
    {
        $results = [];
        $byStore = [];
        foreach ($events as $event) {
            $payload = $this->eventQueue->decodePayload($event);
            $contact = $payload['contact'] ?? null;
            if (!is_array($contact) || empty($contact['email'])) {
                // Terminal: a malformed payload never improves on retry.
                $results[(int)$event->getId()] = 'Malformed contact payload';
                continue;
            }
            $byStore[(int)($payload['store_id'] ?? 0)][] = ['event' => $event, 'contact' => $contact];
        }

        foreach ($byStore as $storeId => $rows) {
            $contacts = array_column($rows, 'contact');
            $client = null;
            try {
                $client = $this->clientProvider->forStore($storeId ?: null);
                $client->post(SmailyClient::ENDPOINT_CONTACT, $contacts);
                $error = null;
            } catch (SmailyClientException $exception) {
                // Handed on whole: only the exception carries the HTTP status
                // and Retry-After the RetryPolicy classifies on.
                $error = $exception;
                $this->logger->info('Contact sync batch failed', [
                    'store_id' => $storeId,
                    'count' => count($rows),
                    'error' => $exception->getMessage(),
                ]);
            }

            $exchange = $client?->lastExchange();
            foreach ($rows as $row) {
                /** @var Event $event */
                $event = $row['event'];
                $results[(int)$event->getId()] = $error ?? true;
                if ($exchange !== null) {
                    // The row's own part of the batch body — never the other
                    // contacts posted with it — and the reply to the batch.
                    $this->eventQueue->recordExchange($event, [$row['contact']], $exchange['response']);
                }
            }
        }

        return $results;
    }
}
