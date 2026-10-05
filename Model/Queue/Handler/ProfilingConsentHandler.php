<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue\Handler;

use Smaily\Connect\Api\Queue\PausableEventHandlerInterface;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\ProfilingOptOuts;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\Failure;
use Smaily\Connect\Model\Queue\Pending;
use Smaily\Connect\Model\Queue\Skipped;

/**
 * Delivers a shopper's queued profiling choice to the engine
 * (POST customer/{email}/opt-out, contract §10) — PRO-3578. Queued so an
 * opt-out survives an engine outage on the normal retry ladder.
 *
 * A row is sent only while it still matches the store's choice
 * (ProfilingOptOuts): a retry, or a Send again from the Log, of a choice the
 * shopper has since replaced closes without a call, so an older answer can
 * never undo a newer one at the engine. The newer choice has a row of its
 * own. Such a row is closed as Skipped with the reason, never as delivered
 * (PRO-3634).
 *
 * While Campaign Intelligence refuses the account (PRO-2451) the choices
 * wait, as the identity merges do (PRO-2466, PRO-3752): the queue does not
 * claim them (isPaused()), and a row that meets the refusal goes back as it
 * was. Once the account is active again they are sent, and the check above
 * still lets only the shopper's newest choice through.
 */
class ProfilingConsentHandler implements PausableEventHandlerInterface
{
    public const SKIPPED_REPLACED = 'Skipped: the shopper has since changed their personalization preference,'
        . ' and the newer preference is sent in its own row. Nothing was sent.';

    private const REASON = 'user_preference';

    public function __construct(
        private readonly Settings $settings,
        private readonly Client $client,
        private readonly EventQueue $eventQueue,
        private readonly ProfilingOptOuts $optOuts,
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
            // Asked per row: a refusal (a 403 on one row) stops the rest,
            // and the rows wait for the account (PRO-3752).
            if ($this->settings->isRefused()) {
                $results[$id] = new Pending();
                continue;
            }
            $blocked = $this->settings->sendingBlockedReason();
            if ($blocked !== null) {
                $results[$id] = $blocked;
                continue;
            }

            $payload = $this->eventQueue->decodePayload($event);
            $email = (string)($payload['email'] ?? '');
            if ($email === '') {
                // Terminal: a malformed payload never improves on retry.
                $results[$id] = Failure::permanent('Malformed profiling consent payload');
                continue;
            }

            $optOut = (bool)($payload['opt_out'] ?? false);
            if ($optOut !== ($this->optOuts->moment($email) !== null)) {
                $this->logger->debug('Profiling choice not sent, a newer one replaced it', ['id' => $id]);
                $results[$id] = new Skipped(self::SKIPPED_REPLACED);
                continue;
            }

            try {
                $this->client->customerOptOut($email, $optOut, self::REASON, (string)($payload['opted_out_at'] ?? ''));
                $results[$id] = true;
            } catch (EngineRequestException $exception) {
                // §10: 404 = the engine holds nothing for this address, so
                // there is nothing to exclude (a newsletter-only guest).
                // Any other 4xx refuses this choice and stops on the first
                // attempt (PRO-1961); the account refusal (contract §2 `403
                // tenant_inactive`) is not this row's fault: it waits.
                $results[$id] = match (true) {
                    $exception->getHttpStatus() === 404 => true,
                    $this->settings->isRefused() => new Pending(),
                    default => $exception,
                };
            } catch (EngineException $exception) {
                // An outage takes the retry ladder (PRO-1961).
                $results[$id] = $exception;
            }

            $exchange = $this->client->lastExchange();
            if ($exchange !== null) {
                $this->eventQueue->recordExchange($event, $exchange['request'], $exchange['response']);
            }
        }

        return $results;
    }

    /**
     * While Campaign Intelligence refuses the account, the rows wait
     * unclaimed (PRO-3752).
     */
    public function isPaused(): bool
    {
        return $this->settings->isRefused();
    }
}
