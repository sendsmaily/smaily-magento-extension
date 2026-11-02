<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue\Handler;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Smaily\Connect\Api\Queue\PausableEventHandlerInterface;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\MergeCustomerSender;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\Failure;
use Smaily\Connect\Model\Queue\Pending;
use Smaily\Connect\Model\Queue\Skipped;

/**
 * Delivers queued identity-merge events (POST identity/merge, contract §7).
 *
 * A shopper who opted out of profiling is not merged (PRO-3578, Woo
 * IdentityHookHandler parity): binding their anonymous browsing to their
 * address is the profiling they said no to. Asked here, on the cron, rather
 * than at login, so the login never waits on a Smaily read. Such a row is
 * closed as Skipped with the reason, never as delivered (PRO-3634).
 *
 * While Campaign Intelligence refuses the account (PRO-2451) the rows wait,
 * as the engine ingest rows do (PRO-2466): the queue does not claim them
 * (isPaused()), and a row that meets the refusal goes back as it was.
 *
 * The merge finds only a customer the engine already has (contract v1.23.0,
 * CON-262), so the customer goes first, in this run (MergeCustomerSender),
 * not through the ingest queue, whose cron may run after this one:
 * - A login with marketing consent (SEND_CUSTOMER, contract §4 group c):
 *   the customer is sent before every merge (§7 "Send the customer first").
 * - A merge that answers `404 customer_not_found` (§7 "Retry after 404"):
 *   the customer is sent when the store sends them anyway (a consenting
 *   login, or a customer the live sync or an import has queued — for
 *   example one who has just registered), and once §4 answered 200 for
 *   them the merge is tried once more. It is never tried again before
 *   that: the row remembers the 404 (CUSTOMER_NOT_FOUND), and its next
 *   attempt sends the customer before it merges. A customer the store does
 *   not send, one the engine refused and one it still does not find park
 *   the row with the reason (NOT_SENT, CUSTOMER_REFUSED,
 *   NOT_FOUND_AFTER_SEND). Retry in the Log sends the customer (where the
 *   store sends them) and the merge again, so a row parked by a 404 links
 *   the login once the customer is sent.
 */
class IdentityMergeHandler implements PausableEventHandlerInterface
{
    /**
     * Contract §7 `merge_reason` of a login: one of the values the engine
     * accepts (`user_logged_in`, `email_provided_at_checkout`, `manual_admin`).
     */
    public const MERGE_REASON_LOGIN = 'user_logged_in';

    /**
     * The `merge_reason` a login row carried before CON-260, which the
     * engine refuses (400): such a row is sent with MERGE_REASON_LOGIN.
     */
    private const LEGACY_MERGE_REASON_LOGIN = 'login';

    /**
     * Payload key: the shopper logged in with marketing consent (contract §4
     * group c), so the customer is sent before the merge. The store's own
     * routing, stripped before the call to the engine.
     */
    public const SEND_CUSTOMER = 'send_customer';

    /**
     * Payload key: the merge answered `404 customer_not_found`, so the next
     * merge waits until §4 answered 200 for the customer. Stripped before
     * the call to the engine.
     */
    public const CUSTOMER_NOT_FOUND = 'customer_not_found';

    /** Why a merge whose customer the store does not send is parked. */
    public const NOT_SENT = 'Campaign Intelligence does not have this customer yet, so the login was not linked'
        . ' to them. The store sends a customer who is in your contact sync, has an order or logs in with'
        . ' marketing consent; Retry links the login after that.';

    /** Why a merge whose customer the engine refused is parked; %1 is the engine's reason. */
    public const CUSTOMER_REFUSED = 'Campaign Intelligence refused the customer (%1), so the login was not linked'
        . ' to them. Retry sends the customer and the login again.';

    /** Why a merge that still finds no customer after the customer was sent is parked. */
    public const NOT_FOUND_AFTER_SEND = 'Campaign Intelligence did not find this customer even after the store'
        . ' sent them, so the login was not linked to them. Retry sends the customer and the login again.';

    public const SKIPPED_OPTED_OUT = 'Skipped: the shopper opted out of personalized recommendations,'
        . ' so their browsing is not linked to their address. Nothing was sent.';

    public function __construct(
        private readonly Settings $settings,
        private readonly Client $client,
        private readonly EventQueue $eventQueue,
        private readonly ProfilingConsent $profilingConsent,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly Logger $logger,
        private readonly MergeCustomerSender $customerSender,
        private readonly IngestQueue $ingestQueue
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handle(array $events): array
    {
        $results = [];
        foreach ($events as $event) {
            $id = (int)$event->getId();
            // Asked per row: a refusal (a 403 on one row) stops the rest,
            // and the rows wait for the account (PRO-2466); not connected, the
            // row says why.
            $blocked = $this->settings->sendingBlockedReason();
            if ($blocked !== null) {
                $results[$id] = $this->settings->isRefused() ? new Pending() : $blocked;
                continue;
            }

            $payload = $this->eventQueue->decodePayload($event);
            if (empty($payload['customer_email'])) {
                // Terminal: a malformed payload never improves on retry.
                $results[$id] = Failure::permanent('Malformed identity merge payload');
                continue;
            }

            $email = (string)$payload['customer_email'];
            if (!$this->profilingConsent->isAllowed($email, $this->storeIdOf($payload))) {
                $this->logger->debug('Identity merge not sent, the shopper opted out of profiling', ['id' => $id]);
                $results[$id] = new Skipped(self::SKIPPED_OPTED_OUT);
                continue;
            }

            $results[$id] = $this->deliver($event, $payload);
        }

        return $results;
    }

    /**
     * While Campaign Intelligence refuses the account, the rows wait
     * unclaimed (PRO-2466).
     */
    public function isPaused(): bool
    {
        return $this->settings->isRefused();
    }

    /**
     * The customer first where the contract asks for it, then the merge,
     * and after a `404 customer_not_found` the one retry (class docblock).
     *
     * @param array<int|string, mixed> $payload
     * @return mixed true, Pending, Failure or the EngineException handed on
     */
    private function deliver(Event $event, array $payload): mixed
    {
        $customerId = (int)($payload['customer_external_id'] ?? 0);
        $consented = !empty($payload[self::SEND_CUSTOMER]);
        $metNotFound = !empty($payload[self::CUSTOMER_NOT_FOUND]) || $this->lastAnswerWasNotFound($event);
        // store_id and the flags are the store's own routing, not part of §7.
        unset($payload['store_id'], $payload[self::SEND_CUSTOMER], $payload[self::CUSTOMER_NOT_FOUND]);
        if (($payload['merge_reason'] ?? null) === self::LEGACY_MERGE_REASON_LOGIN) {
            $payload['merge_reason'] = self::MERGE_REASON_LOGIN;
        }

        $tried = false;
        $refusal = null;
        if ($consented || ($metNotFound && $this->isQueuedCustomer($customerId))) {
            $tried = true;
            try {
                $refusal = $this->customerSender->send($customerId);
            } catch (EngineException $exception) {
                return $this->failed($exception);
            }
        }
        // After a 404 this merge is the one retry; a first merge has one left.
        $retryLeft = !$metNotFound;

        while (true) {
            try {
                $this->merge($event, $payload);

                return true;
            } catch (EngineException $exception) {
                if (!self::isCustomerNotFound($exception)) {
                    return $this->failed($exception);
                }
            }

            $this->eventQueue->addToPayload($event, [self::CUSTOMER_NOT_FOUND => true]);
            if (!$tried && $this->isQueuedCustomer($customerId)) {
                $tried = true;
                try {
                    $refusal = $this->customerSender->send($customerId);
                } catch (EngineException $exception) {
                    return $this->failed($exception);
                }
            }
            if (!$tried || $refusal === MergeCustomerSender::NO_ACCOUNT) {
                return Failure::permanent(self::NOT_SENT);
            }
            if ($refusal !== null) {
                return Failure::permanent(str_replace('%1', $refusal, self::CUSTOMER_REFUSED));
            }
            if (!$retryLeft) {
                return Failure::permanent(self::NOT_FOUND_AFTER_SEND);
            }
            $retryLeft = false;
        }
    }

    /**
     * Call the merge and record it on the row, whatever the answer.
     *
     * @param array<int|string, mixed> $payload
     * @throws EngineException
     */
    private function merge(Event $event, array $payload): void
    {
        try {
            $this->client->identityMerge($payload);
        } finally {
            $exchange = $this->client->lastExchange();
            if ($exchange !== null) {
                $this->eventQueue->recordExchange($event, $exchange['request'], $exchange['response']);
            }
        }
    }

    /**
     * A failed call handed on whole: a 4xx refuses this payload and stops
     * on the first attempt, an outage takes the ladder (PRO-1961). The
     * account refusal (contract §2 `403 tenant_inactive`) is not this row's
     * fault: it waits (PRO-2466).
     */
    private function failed(EngineException $exception): object
    {
        return $this->settings->isRefused() ? new Pending() : $exception;
    }

    private static function isCustomerNotFound(EngineException $exception): bool
    {
        return $exception instanceof EngineRequestException
            && $exception->getHttpStatus() === 404
            && ($exception->getErrorBody()['error'] ?? null) === 'customer_not_found';
    }

    /**
     * Whether the row's last recorded answer is `404 customer_not_found`: a
     * row parked by that answer before CON-262 carries no
     * CUSTOMER_NOT_FOUND, and its Retry must not merge before the customer
     * is sent.
     */
    private function lastAnswerWasNotFound(Event $event): bool
    {
        $response = json_decode((string)$event->getData('last_response'), true);

        return is_array($response)
            && (int)($response['http_status'] ?? 0) === 404
            && is_array($response['body'] ?? null)
            && ($response['body']['error'] ?? null) === 'customer_not_found';
    }

    /**
     * Whether the store sends this customer anyway: a customer the live
     * sync or an import has queued (CON-262).
     */
    private function isQueuedCustomer(int $customerId): bool
    {
        return $customerId > 0 && $this->ingestQueue->hasRow(Client::DOMAIN_CUSTOMERS, (string)$customerId);
    }

    /**
     * The customer's store view, whose Smaily account holds their consent:
     * queued with the row at login. A row queued before the store view was
     * part of the payload looks the customer up; null (the default scope)
     * when the account is gone.
     *
     * @param array<int|string, mixed> $payload
     */
    private function storeIdOf(array $payload): ?int
    {
        if (isset($payload['store_id'])) {
            return (int)$payload['store_id'];
        }

        try {
            return (int)$this->customerRepository->getById((int)($payload['customer_external_id'] ?? 0))
                ->getStoreId();
        } catch (LocalizedException) {
            return null;
        }
    }
}
