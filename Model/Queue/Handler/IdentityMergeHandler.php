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
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\Model\Queue\EventQueue;

/**
 * Delivers queued identity-merge events (POST identity/merge, contract §7).
 *
 * A shopper who opted out of profiling is not merged (PRO-3578, Woo
 * IdentityHookHandler parity): binding their anonymous browsing to their
 * address is the profiling they said no to. Asked here, on the cron, rather
 * than at login, so the login never waits on a Smaily read.
 */
class IdentityMergeHandler implements EventHandlerInterface
{
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
        // Connectedness cannot change under us mid-batch; a refusal can (a
        // 403 on one row stops the rest), so only that half is re-asked.
        $connected = $this->settings->isConnected();
        foreach ($events as $event) {
            $id = (int)$event->getId();
            $refused = $this->settings->isRefused();
            if (!$connected || $refused) {
                $results[$id] = $refused
                    ? 'Campaign Intelligence account is not active'
                    : 'Campaign Intelligence is not connected';
                continue;
            }

            $payload = $this->eventQueue->decodePayload($event);
            if (empty($payload['customer_email'])) {
                $results[$id] = 'Malformed identity merge payload';
                continue;
            }

            $email = (string)$payload['customer_email'];
            if (!$this->profilingConsent->isAllowed($email, $this->storeIdOf($payload))) {
                $this->logger->debug('Identity merge not sent, the shopper opted out of profiling', ['id' => $id]);
                $results[$id] = true;
                continue;
            }

            try {
                $this->client->identityMerge($payload);
                $results[$id] = true;
            } catch (EngineRequestException $exception) {
                // 4xx is terminal for this payload; report and stop retrying
                // by letting the row exhaust naturally with the same message.
                $results[$id] = $exception->getMessage();
            } catch (EngineException $exception) {
                $results[$id] = $exception->getMessage();
            }
        }

        return $results;
    }

    /**
     * The customer's store view, whose Smaily account holds their consent;
     * null (the default scope) when the account is gone.
     *
     * @param array<int|string, mixed> $payload
     */
    private function storeIdOf(array $payload): ?int
    {
        try {
            return (int)$this->customerRepository->getById((int)($payload['customer_external_id'] ?? 0))
                ->getStoreId();
        } catch (LocalizedException) {
            return null;
        }
    }
}
