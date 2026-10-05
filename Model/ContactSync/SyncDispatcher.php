<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ContactSync;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\Queue\ContactEntity;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;

/**
 * Builds payloads and enqueues contact-sync and automation events. The thin
 * observers delegate here so every dispatch path shares one payload shape.
 * Their rows' entity is the contact's, by ContactEntity (PRO-3767).
 */
class SyncDispatcher
{
    /** How many addresses the last-queued memory below holds at most. */
    private const QUEUED_MEMORY = 100;

    /**
     * The last contact sync this process queued per store and address.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $lastQueued = [];

    public function __construct(
        private readonly SubscriberPayloadBuilder $payloadBuilder,
        private readonly LanguageResolver $languageResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly EventQueue $eventQueue,
        private readonly ContactEntity $contactEntity
    ) {
    }

    /**
     * Queue one contact sync, unless it adds nothing to the one this request
     * already queued for the address (PRO-3628). A storefront registration
     * with the newsletter box saves the customer twice — the account, then
     * the password-reset token — and subscribes between the two saves, so
     * the second save would queue the subscription's contact again. A sync
     * whose every field is already in the last one, with the same value,
     * changes nothing in Smaily. One that changes or adds a field is queued.
     * The queue's event_uuid idempotency is not used here: it would also
     * drop a sync that returns to an earlier state (subscribe, unsubscribe,
     * subscribe again).
     */
    public function dispatchContactSync(
        string $email,
        int $storeId,
        ?bool $isUnsubscribed,
        ?CustomerInterface $customer = null
    ): void {
        $contact = $this->payloadBuilder->build($email, $storeId, $isUnsubscribed, $customer);
        $key = $storeId . '|' . ($contact['email'] ?? '');
        if (isset($this->lastQueued[$key]) && $this->addsNothing($contact, $this->lastQueued[$key])) {
            return;
        }

        $this->enqueueContactSync($contact, $storeId);
        unset($this->lastQueued[$key]);
        $this->lastQueued[$key] = $contact;
        if (count($this->lastQueued) > self::QUEUED_MEMORY) {
            unset($this->lastQueued[array_key_first($this->lastQueued)]);
        }
    }

    /**
     * Stamps the trigger's own run marker onto the address and enqueues it.
     * The stamp is taken here, where the trigger FIRES, not when the queue row
     * is POSTed — a retry then resends the moment the store event happened,
     * which is the moment a merchant means. See Trigger::MARKER_FIELDS.
     *
     * @param array<string, string|int> $address must contain "email"
     */
    public function dispatchAutomation(string $trigger, int $storeId, array $address): void
    {
        $this->eventQueue->enqueue(
            EventType::AUTOMATION_TRIGGER,
            $this->automationPayload($trigger, $storeId, $address),
            $this->contactEntity->of((string)($address['email'] ?? '')),
            $this->websiteId($storeId)
        );
    }

    /**
     * Records an automation in the Log as skipped, with the reason, instead
     * of queueing it to be sent; the row shows what dispatchAutomation()
     * would have sent.
     *
     * @param array<string, string|int> $address must contain "email"
     */
    public function recordSkippedAutomation(string $trigger, int $storeId, array $address, string $reason): void
    {
        $this->eventQueue->enqueueSkipped(
            EventType::AUTOMATION_TRIGGER,
            $this->automationPayload($trigger, $storeId, $address),
            $reason,
            $this->contactEntity->of((string)($address['email'] ?? '')),
            $this->websiteId($storeId)
        );
    }

    /**
     * An automation's queue payload, its address stamped with the trigger's
     * own run marker.
     *
     * @param array<string, string|int> $address
     * @return array<string, mixed>
     */
    private function automationPayload(string $trigger, int $storeId, array $address): array
    {
        $marker = Trigger::MARKER_FIELDS[$trigger] ?? null;
        if ($marker !== null) {
            $address[$marker] = gmdate(Trigger::MARKER_STAMP_FORMAT);
        }

        return [
            'trigger_type' => $trigger,
            'store_id' => $storeId,
            'website_id' => $this->websiteId($storeId),
            'language' => $this->languageResolver->forStore($storeId),
            'address' => $address,
        ];
    }

    /**
     * The abandoned-cart workflow's exit signal, for a contact the store has
     * already tracked as abandoned (PRO-2453). Two halves of one thing: a
     * reminder still waiting in the queue is withdrawn, and the purchase
     * moment goes onto the contact as a plain contact.sync carrying the
     * address and that one field — no automation runs, and the reminder's
     * own cart and product fields are left exactly as the reminder wrote
     * them. Stamped here, at the order-placed moment, for the same reason
     * dispatchAutomation() stamps its marker at the trigger.
     *
     * Smaily creates a contact sent without a status as subscribed, so the
     * marker goes only when a reminder went out to Smaily (PRO-3619): one
     * withdrawn, still waiting or given up reached no contact, and the marker
     * would create one. A reminder to an address Smaily does not have
     * creates nothing either, so the queue handler reads the contact before
     * it posts the marker and skips it for an address Smaily does not have
     * (ContactSyncHandler). An email the store knows as unsubscribed carries
     * is_unsubscribed=1 (PRO-3616); otherwise the status is omitted and the
     * contact keeps the one it has.
     */
    public function dispatchCartPurchase(string $email, int $storeId, bool $unsubscribedInStore = false): void
    {
        $entity = $this->contactEntity->of($email);
        $this->eventQueue->cancelPendingAutomation(Trigger::ABANDONED_CART, $entity);
        if (!$this->eventQueue->hasDeliveredAutomation(Trigger::ABANDONED_CART, $entity)) {
            return;
        }

        $contact = [
            'email' => $email,
            Trigger::ABANDONED_CART_PURCHASED_FIELD => gmdate(Trigger::MARKER_STAMP_FORMAT),
        ];
        if ($unsubscribedInStore) {
            $contact['is_unsubscribed'] = 1;
        }
        $this->enqueueContactSync($contact, $storeId);
    }

    /**
     * Queue one contact.sync for an already-built contact payload.
     *
     * @param array<string, mixed> $contact must contain "email"
     */
    private function enqueueContactSync(array $contact, int $storeId): void
    {
        $this->eventQueue->enqueue(
            EventType::CONTACT_SYNC,
            ['store_id' => $storeId, 'contact' => $contact],
            $this->contactEntity->of((string)($contact['email'] ?? '')),
            $this->websiteId($storeId)
        );
    }

    /**
     * Whether every field of $contact is already in $queued, with the same value.
     *
     * @param array<string, mixed> $contact
     * @param array<string, mixed> $queued
     */
    private function addsNothing(array $contact, array $queued): bool
    {
        foreach ($contact as $field => $value) {
            if (!array_key_exists($field, $queued) || $queued[$field] !== $value) {
                return false;
            }
        }

        return true;
    }

    public function websiteId(int $storeId): int
    {
        try {
            return (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        } catch (LocalizedException) {
            return 0;
        }
    }
}
