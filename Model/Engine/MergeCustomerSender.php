<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Payload\CustomerPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;

/**
 * Sends the customer of an identity merge to Campaign Intelligence (contract
 * §4) right before the merge (§7), in the merge's own run
 * (Queue\Handler\IdentityMergeHandler, CON-262; contract v1.23.0 "Send the
 * customer first" and "Retry after 404"). The merge finds only a customer
 * the engine already has, and the customer row and the merge travel in two
 * queues whose crons run in either order: sent here, the customer is in
 * the engine before the merge, whatever the cron order.
 *
 * The row is built from the account as it is now (Payload\CustomerPayloadBuilder,
 * the row a customer save queues) and goes alone, with its own `event_id`.
 * The Log records it as a customers row (IngestQueue::newEvent(), as the
 * nightly catalog manifest is recorded): delivered when the engine took
 * it, failed with the engine's reason when it refused the item. A request
 * that fails is not recorded here: the merge row carries the failure and
 * tries again, customer first.
 */
class MergeCustomerSender
{
    /**
     * What send() answers when there is no account to send.
     */
    public const NO_ACCOUNT = 'no_account';

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerPayloadBuilder $payloadBuilder,
        private readonly Client $client,
        private readonly IngestQueue $ingestQueue
    ) {
    }

    /**
     * Send the customer with §4.
     *
     * @return string|null null when the engine answered 200 and took the
     *         customer; else why not: NO_ACCOUNT, or the engine's refusal
     *         of the item (`field: message`)
     * @throws EngineException the request failed (refused as a whole, or no answer)
     */
    public function send(int $customerId): ?string
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (LocalizedException) {
            return self::NO_ACCOUNT;
        }

        $event = $this->ingestQueue->newEvent(Client::DOMAIN_CUSTOMERS, $this->payloadBuilder->build($customer));
        $event->addData(['entity_id' => (string)$customerId, 'store_id' => (int)$customer->getStoreId()]);
        $item = $this->ingestQueue->decodePayload($event);

        $response = $this->client->ingest(Client::DOMAIN_CUSTOMERS, [$item]);

        $exchange = $this->client->lastExchange();
        if ($exchange !== null) {
            $this->ingestQueue->recordExchange($event, $item, $exchange['response']);
        }
        $refusal = self::itemRefusal($response);
        if ($refusal !== null) {
            $this->ingestQueue->markFailed($event, $refusal, true);

            return $refusal;
        }
        $this->ingestQueue->markSent($event);

        return null;
    }

    /**
     * The engine's refusal of the one item, as the ingest flush words a
     * per-item error (`field: message`); null when it took the item.
     *
     * @param array<string, mixed> $response
     */
    private static function itemRefusal(array $response): ?string
    {
        foreach ((array)($response['errors'] ?? []) as $error) {
            if (is_array($error) && (int)($error['index'] ?? -1) === 0) {
                return sprintf(
                    '%s: %s',
                    (string)($error['field'] ?? 'item'),
                    (string)($error['message'] ?? 'rejected by engine')
                );
            }
        }

        return null;
    }
}
