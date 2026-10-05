<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue\Handler;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Smaily\Connect\Api\Queue\EventHandlerInterface;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\Failure;
use Smaily\Connect\Model\Queue\Skipped;

/**
 * Delivers queued identity-merge events (POST identity/merge, contract §7).
 *
 * A shopper who opted out of profiling is not merged (PRO-3578, Woo
 * IdentityHookHandler parity): binding their anonymous browsing to their
 * address is the profiling they said no to. Asked here, on the cron, rather
 * than at login, so the login never waits on a Smaily read. Such a row is
 * closed as Skipped with the reason, never as delivered (PRO-3634).
 */
class IdentityMergeHandler implements EventHandlerInterface
{
    public const SKIPPED_OPTED_OUT = 'Skipped: the shopper opted out of personalized recommendations,'
        . ' so their browsing is not linked to their address. Nothing was sent.';

    public function __construct(
        private readonly Settings $settings,
        private readonly Client $client,
        private readonly EventQueue $eventQueue,
        private readonly ProfilingConsent $profilingConsent,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly Logger $logger
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
            // Asked per row: a refusal (a 403 on one row) stops the rest.
            $blocked = $this->settings->sendingBlockedReason();
            if ($blocked !== null) {
                $results[$id] = $blocked;
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

            // store_id is the store's own routing, not part of §7.
            unset($payload['store_id']);
            try {
                $this->client->identityMerge($payload);
                $results[$id] = true;
            } catch (EngineException $exception) {
                // Handed on whole: a 4xx refuses this payload and stops on
                // the first attempt, an outage takes the ladder (PRO-1961).
                // The account refusal (contract §2 `403 tenant_inactive`) is
                // not this row's fault, so it stays on the ladder.
                $results[$id] = $this->settings->isRefused() ? $exception->getMessage() : $exception;
            }

            $exchange = $this->client->lastExchange();
            if ($exchange !== null) {
                $this->eventQueue->recordExchange($event, $exchange['request'], $exchange['response']);
            }
        }

        return $results;
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
