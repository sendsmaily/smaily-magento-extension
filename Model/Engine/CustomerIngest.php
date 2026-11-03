<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Backfill\EngineCustomerScope;
use Smaily\Connect\Model\Backfill\HistoryImport;
use Smaily\Connect\Model\Engine\Payload\CustomerPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;

/**
 * The live customer sync to Campaign Intelligence (contract §4): queues a
 * registered customer's row only when the customer is in the customer
 * scope (contract v1.20.0 and v1.23.0 §4; CON-266, Erkki 2026-11-02):
 *
 * - (a) the store's own contact sync puts the customer into Smaily, by the
 *   website's sync mode, and (b) the customer has an order inside the
 *   history horizon — the customers import's rule, asked for this one
 *   customer (Backfill\EngineCustomerScope::includes(), horizon
 *   Backfill\HistoryImport::horizonNow(): the stored window, never a ping);
 * - (c) the customer logs in with marketing consent: sent at that login
 *   (Engine\MergeCustomerSender); a later save the same shopper makes,
 *   logged in with consent on the store's own pages, is sent too
 *   (ConsentingShopper), so the engine's row does not go stale.
 *
 * Every other customer is not queued; nothing removes a customer the
 * engine already has, so a customer who leaves every group only stops
 * getting updates.
 *
 * A customer who joins a group later is queued at that moment (joined()):
 * a newsletter subscription (Observer\Engine\SubscriberJoined) and an
 * order of the account (Observer\Engine\OrderSaveAfter). A change of a
 * website's sync mode moves whole groups at once and sends nobody live:
 * the customers import's Run again sends them. A save always queues the
 * account as saved; a joining event queues it only when this request has
 * not queued it already (the row is the same).
 *
 * The scope is each Campaign Intelligence connection's (CON-237): a
 * customer is queued for every connected connection whose scope holds
 * them — groups (a) and (b) on its websites and store views, with its own
 * horizon — each with the row built for it; group (c) holds only for the
 * connection of the store view the shopper is on. Under one store that is
 * the primary alone, as before.
 */
class CustomerIngest
{
    /** @var array<int, array<int, true>> connection key => customers queued for it in this request */
    private array $queued = [];

    public function __construct(
        private readonly CustomerPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue,
        private readonly EngineCustomerScope $scope,
        private readonly HistoryImport $historyImport,
        private readonly ConsentingShopper $consentingShopper,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly Connections $connections,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The account was saved: queue its row for each connection whose scope
     * holds the customer.
     */
    public function saved(CustomerInterface $customer): void
    {
        if (!$customer->getEmail()) {
            return;
        }
        $customerId = (int)$customer->getId();
        foreach ($this->connections->connected() as $connection) {
            if ($this->inScope($customerId, $connection)) {
                $this->enqueue($customer, $connection);
            }
        }
    }

    /**
     * The customer may have just joined the scope (a subscription, an
     * order): queue the account's row for each connection whose scope holds
     * the customer and that this request has not queued it for already.
     */
    public function joined(int $customerId): void
    {
        if ($customerId <= 0) {
            return;
        }
        $customer = null;
        foreach ($this->connections->connected() as $connection) {
            if (isset($this->queued[$connection->key()][$customerId]) || !$this->inScope($customerId, $connection)) {
                continue;
            }
            if ($customer === null) {
                try {
                    $customer = $this->customerRepository->getById($customerId);
                } catch (LocalizedException) {
                    return;
                }
            }
            if (!$customer->getEmail()) {
                return;
            }

            $this->enqueue($customer, $connection);
        }
    }

    private function inScope(int $customerId, Connection $connection): bool
    {
        return ($this->consentingShopper->isLoggedInWithConsent($customerId) && $this->isCurrent($connection))
            || $this->scope->includes($customerId, $this->historyImport->horizonNow($connection), $connection);
    }

    /**
     * Whether the connection is the one of the store view this request is
     * on — group (c): the shopper consented there.
     */
    private function isCurrent(Connection $connection): bool
    {
        if ($connection->servesAllWebsites()) {
            return true;
        }
        try {
            $storeId = (int)$this->storeManager->getStore()->getId();
        } catch (LocalizedException) {
            return false;
        }

        return $this->connections->forStore($storeId)?->key() === $connection->key();
    }

    private function enqueue(CustomerInterface $customer, Connection $connection): void
    {
        $customerId = (int)$customer->getId();
        $this->ingestQueue->enqueue(
            Client::DOMAIN_CUSTOMERS,
            $this->payloadBuilder->build($customer, $connection),
            (string)$customerId,
            $connection->canonicalStoreId()
        );
        $this->queued[$connection->key()][$customerId] = true;
    }
}
