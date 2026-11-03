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
 */
class CustomerIngest
{
    /** @var array<int, true> customers queued in this request */
    private array $queued = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly CustomerPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue,
        private readonly EngineCustomerScope $scope,
        private readonly HistoryImport $historyImport,
        private readonly ConsentingShopper $consentingShopper,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly Connections $connections
    ) {
    }

    /**
     * The account was saved: queue its row when the customer is in scope.
     */
    public function saved(CustomerInterface $customer): void
    {
        if (!$this->settings->isConnected() || !$customer->getEmail()) {
            return;
        }
        $customerId = (int)$customer->getId();
        if (!$this->inScope($customerId)) {
            return;
        }

        $this->enqueue($customer);
    }

    /**
     * The customer may have just joined the scope (a subscription, an
     * order): queue the account's row when the customer is in scope and
     * this request has not queued it already.
     */
    public function joined(int $customerId): void
    {
        if ($customerId <= 0 || isset($this->queued[$customerId]) || !$this->settings->isConnected()) {
            return;
        }
        if (!$this->inScope($customerId)) {
            return;
        }
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (LocalizedException) {
            return;
        }
        if (!$customer->getEmail()) {
            return;
        }

        $this->enqueue($customer);
    }

    private function inScope(int $customerId): bool
    {
        return $this->consentingShopper->isLoggedInWithConsent($customerId)
            || $this->scope->includes($customerId, $this->historyImport->horizonNow());
    }

    private function enqueue(CustomerInterface $customer): void
    {
        $customerId = (int)$customer->getId();
        // The primary connection's row (CON-237; per-connection rows are a later step).
        $this->ingestQueue->enqueue(
            Client::DOMAIN_CUSTOMERS,
            $this->payloadBuilder->build($customer),
            (string)$customerId,
            $this->connections->primary()->canonicalStoreId()
        );
        $this->queued[$customerId] = true;
    }
}
