<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue\Handler;

use Smaily\Connect\Api\Queue\EventHandlerInterface;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\ProfilingOptOuts;
use Smaily\Connect\Model\Queue\EventQueue;

/**
 * Delivers a shopper's queued profiling choice to the engine
 * (POST customer/{email}/opt-out, contract §10) — PRO-3578. Queued so an
 * opt-out survives an engine outage on the normal retry ladder.
 *
 * A row is sent only while it still matches the store's choice
 * (ProfilingOptOuts): a retry, or a Send again from the Log, of a choice the
 * shopper has since replaced closes without a call, so an older answer can
 * never undo a newer one at the engine. The newer choice has a row of its
 * own.
 */
class ProfilingConsentHandler implements EventHandlerInterface
{
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
            // Asked per row: a refusal (a 403 on one row) stops the rest.
            $blocked = $this->settings->sendingBlockedReason();
            if ($blocked !== null) {
                $results[$id] = $blocked;
                continue;
            }

            $payload = $this->eventQueue->decodePayload($event);
            $email = (string)($payload['email'] ?? '');
            if ($email === '') {
                $results[$id] = 'Malformed profiling consent payload';
                continue;
            }

            $optOut = (bool)($payload['opt_out'] ?? false);
            if ($optOut !== ($this->optOuts->moment($email) !== null)) {
                $this->logger->debug('Profiling choice not sent, a newer one replaced it', ['id' => $id]);
                $results[$id] = true;
                continue;
            }

            try {
                $this->client->customerOptOut($email, $optOut, self::REASON, (string)($payload['opted_out_at'] ?? ''));
                $results[$id] = true;
            } catch (EngineRequestException $exception) {
                // §10: 404 = the engine holds nothing for this address, so
                // there is nothing to exclude (a newsletter-only guest).
                $results[$id] = $exception->getHttpStatus() === 404 ? true : $exception->getMessage();
            } catch (EngineException $exception) {
                $results[$id] = $exception->getMessage();
            }
        }

        return $results;
    }
}
