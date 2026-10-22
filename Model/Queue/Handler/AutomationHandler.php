<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue\Handler;

use Smaily\Connect\Api\Queue\EventHandlerInterface;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\MultilingualMode;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\Failure;
use Smaily\Connect\Model\Queue\Skipped;

/**
 * Delivers queued automation.trigger events via POST /api/autoresponder.php.
 *
 * Event payload shape:
 * {trigger_type, store_id, website_id, language, address: {email, ...fields}}.
 *
 * Events are dispatched one by one (never batched) so a partial failure
 * cannot re-trigger an automation for an address that already received it.
 * A missing workflow mapping is a terminal skip, not a failure — retrying
 * cannot make a mapping appear (matches the Woo AutomationRouter contract).
 * The row is closed as Skipped with the reason, never as delivered
 * (PRO-3634).
 *
 * Credentials follow the resolved match (Woo parity): when a mapping row
 * matched, the row's account_key names the Smaily account that must deliver
 * the workflow — mode-A fallback rows included, so another account's
 * workflow ID is never posted through the event's store-view credentials.
 * Config-default resolutions (modes single, b and c) keep using the event's
 * store view as before; mode A has none (Router, CON-188), and its skip
 * names the contact's language.
 *
 * force_opt_in is always false, in every contact-sync mode (PRO-3577, as
 * the WooCommerce plugin's PRO-1716): a trigger never overrides an
 * unsubscribe the contact made in Smaily. Without force opt-in, a trigger
 * for an address Smaily does not have creates no contact and sends nothing
 * (Smaily, confirmed 2026-10-02).
 */
class AutomationHandler implements EventHandlerInterface
{
    public const SKIPPED_NO_WORKFLOW = 'Skipped: no Smaily workflow is mapped to this automation trigger.'
        . ' Nothing was sent.';

    /**
     * Per-language Smaily accounts (mode A, CON-188): neither the contact's
     * language, the trigger's default fallback nor the default fallback
     * account's language has a workflow. %1 is the language code.
     */
    public const SKIPPED_NO_LANGUAGE_WORKFLOW = 'Skipped: this automation has no Smaily workflow for the'
        . ' contact\'s language (%1), and no default fallback workflow either. Nothing was sent.';

    public function __construct(
        private readonly SmailyClientProvider $clientProvider,
        private readonly Router $router,
        private readonly EventQueue $eventQueue,
        private readonly Logger $logger,
        private readonly AccountResolver $accountResolver,
        private readonly Config $config
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
            $payload = $this->eventQueue->decodePayload($event);

            $trigger = (string)($payload['trigger_type'] ?? '');
            $address = $payload['address'] ?? null;
            if ($trigger === '' || !is_array($address) || empty($address['email'])) {
                // Terminal: a malformed payload never improves on retry.
                $results[$id] = Failure::permanent('Malformed automation payload');
                continue;
            }

            $websiteId = (int)($payload['website_id'] ?? 0);
            $language = (string)($payload['language'] ?? '');
            $match = $this->router->resolve($trigger, $websiteId, $language);
            if ($match === null) {
                // Terminal skip: no workflow mapped for this trigger/language.
                $this->logger->debug('Automation skipped, no workflow mapped', [
                    'trigger' => $trigger,
                    'website_id' => $websiteId,
                ]);
                $perLanguage = $language !== ''
                    && $this->config->getMultilingualMode($websiteId) === MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS;
                $results[$id] = new Skipped($perLanguage
                    ? str_replace('%1', strtoupper($language), self::SKIPPED_NO_LANGUAGE_WORKFLOW)
                    : self::SKIPPED_NO_WORKFLOW);
                continue;
            }

            $client = null;
            try {
                $storeId = (int)($payload['store_id'] ?? 0);
                $clientStoreId = $storeId ?: null;
                if ($match->accountKey !== null) {
                    // The mapping row names the account. 'default' (and an
                    // account whose language no longer has a store view)
                    // resolves to null = the default-scope credentials.
                    $clientStoreId = $this->accountResolver->storeIdForAccountKey($match->accountKey, $websiteId);
                }
                $client = $this->clientProvider->forStore($clientStoreId);
                $client->post(SmailyClient::ENDPOINT_AUTORESPONDER, [
                    'autoresponder' => $match->workflowId,
                    'addresses' => [$address],
                    'force_opt_in' => false,
                ]);
                $results[$id] = true;
            } catch (SmailyClientException $exception) {
                // Handed on whole: only the exception carries the HTTP status
                // and Retry-After the queue classifies on (Failure::of()).
                $results[$id] = $exception;
            }

            $exchange = $client?->lastExchange();
            if ($exchange !== null) {
                $this->eventQueue->recordExchange($event, $exchange['request'], $exchange['response']);
            }
        }

        return $results;
    }
}
