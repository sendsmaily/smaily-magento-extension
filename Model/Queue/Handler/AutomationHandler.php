<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue\Handler;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Api\Queue\EventHandlerInterface;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Automation\WorkflowMatch;
use Smaily\Connect\Model\Client\Exception\ApiException;
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
 * The 'default' account is the event website's account — its own, else the
 * store-wide one — never the store-wide account alone (CON-189).
 * Config-default resolutions (modes single, b and c) keep using the event's
 * store view as before; mode A has none (Router, CON-188), and its skip
 * names the contact's language. A website on another Smaily account than
 * the store-wide one has only its own workflows (Router, CON-190), and its
 * skip names the website.
 *
 * force_opt_in is always false, in every contact-sync mode (PRO-3577, as
 * the WooCommerce plugin's PRO-1716): a trigger never overrides an
 * unsubscribe the contact made in Smaily. A trigger for an address that is
 * not a contact in the Smaily account adds it as a contact (Smaily,
 * confirmed 2026-10-23; the WooCommerce plugin's PRO-1678 rule); the
 * trigger's marker field (Trigger::MARKER_FIELDS) lets the merchant find or
 * exclude those contacts in a Smaily segment.
 *
 * An abandoned-cart reminder for a shopper who has not agreed to marketing
 * emails in the store (consent and checkout opt-in modes, CON-195) carries
 * SUBSCRIBED_CONTACT_CART: it goes only to a contact the workflow's Smaily
 * account has as subscribed. One read of the contact, through the account
 * that would send it; any other answer closes the row as Skipped and the
 * cart's tracker row as skipped, and a read that fails leaves the row on the
 * retry ladder with nothing sent.
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

    /**
     * A website on another Smaily account than the store-wide one (CON-190)
     * has no workflow of its account for this automation; the store-wide
     * workflows belong to the store-wide account. %1 is the website's name.
     */
    public const SKIPPED_NO_OWN_ACCOUNT_WORKFLOW = 'Skipped: the website %1 uses another Smaily account than the one'
        . ' saved for all websites, and no workflow of its account is picked for this automation. Nothing was sent.';

    /**
     * A reminder for a shopper without store consent that Smaily does not
     * have as a subscribed contact (CON-195).
     */
    public const SKIPPED_NO_MARKETING_CONSENT = 'Skipped: this shopper has not agreed to marketing emails in the'
        . ' store, and is not a subscribed contact in Smaily. Nothing was sent.';

    /**
     * Payload key of a reminder that goes only to a subscribed contact in
     * Smaily; holds the cart's quote id (SyncDispatcher, CON-195).
     */
    public const SUBSCRIBED_CONTACT_CART = 'subscribed_contact_cart';

    public function __construct(
        private readonly SmailyClientProvider $clientProvider,
        private readonly Router $router,
        private readonly EventQueue $eventQueue,
        private readonly Logger $logger,
        private readonly AccountResolver $accountResolver,
        private readonly Config $config,
        private readonly WebsiteOverrides $overrides,
        private readonly StoreManagerInterface $storeManager,
        private readonly StateManager $cartState
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
                $results[$id] = new Skipped($this->skipReason($websiteId, $language));
                continue;
            }

            try {
                $client = $this->clientFor($match, (int)($payload['store_id'] ?? 0), $websiteId);
                $skipped = isset($payload[self::SUBSCRIBED_CONTACT_CART])
                    && !$this->isSubscribedContact($client, (string)$address['email']);
            } catch (SmailyClientException $exception) {
                // Nothing was posted: the row waits on the retry ladder.
                $results[$id] = $exception;
                continue;
            }
            if ($skipped) {
                $this->cartState->markReminderSkipped((int)$payload[self::SUBSCRIBED_CONTACT_CART]);
                $results[$id] = new Skipped(self::SKIPPED_NO_MARKETING_CONSENT);
                continue;
            }

            try {
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

            $exchange = $client->lastExchange();
            if ($exchange !== null) {
                $this->eventQueue->recordExchange($event, $exchange['request'], $exchange['response']);
            }
        }

        return $results;
    }

    /**
     * The Smaily account that delivers the matched workflow. The mapping row
     * names the account: 'default' (and an account whose language no longer
     * has a store view) is the website's account — its own, else the
     * store-wide one (CON-189); a config-default match goes through the
     * event's store view.
     */
    private function clientFor(WorkflowMatch $match, int $storeId, int $websiteId): SmailyClient
    {
        if ($match->accountKey === null) {
            return $this->clientProvider->forStore($storeId ?: null);
        }
        $accountStoreId = $this->accountResolver->storeIdForAccountKey($match->accountKey, $websiteId);

        return $accountStoreId === null
            ? $this->clientProvider->forWebsite($websiteId)
            : $this->clientProvider->forStore($accountStoreId);
    }

    /**
     * Whether the account has the address as a subscribed contact. An
     * address it does not have (code 206) is not one.
     *
     * @throws SmailyClientException when Smaily cannot be read
     */
    private function isSubscribedContact(SmailyClient $client, string $email): bool
    {
        try {
            $contact = $client->get(SmailyClient::ENDPOINT_CONTACT, ['email' => $email]);
        } catch (ApiException $exception) {
            if ($exception->getSmailyCode() === ApiException::CODE_EMAIL_NOT_FOUND) {
                return false;
            }
            throw $exception;
        }
        $fields = isset($contact[0]) && is_array($contact[0]) ? $contact[0] : $contact;

        return (string)($fields['is_unsubscribed'] ?? '') === '0';
    }

    /**
     * Why no workflow was found: the contact's language in mode A, the
     * website on another Smaily account, else no mapped workflow.
     */
    private function skipReason(int $websiteId, string $language): string
    {
        if ($language !== ''
            && $this->config->getMultilingualMode($websiteId) === MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS
        ) {
            return str_replace('%1', strtoupper($language), self::SKIPPED_NO_LANGUAGE_WORKFLOW);
        }
        if ($this->overrides->usesOtherAccount($websiteId)) {
            try {
                $name = (string)$this->storeManager->getWebsite($websiteId)->getName();
            } catch (LocalizedException) {
                $name = (string)$websiteId;
            }

            return str_replace('%1', $name, self::SKIPPED_NO_OWN_ACCOUNT_WORKFLOW);
        }

        return self::SKIPPED_NO_WORKFLOW;
    }
}
