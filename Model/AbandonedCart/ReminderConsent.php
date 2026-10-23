<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\AbandonedCart;

use Magento\Framework\App\ResourceConnection;
use Magento\Newsletter\Model\Subscriber;
use Smaily\Connect\Model\ContactSync\Mode;

/**
 * The store's half of who gets an abandoned-cart reminder, by the website's
 * contact-sync mode (CON-195, CON-196 — the same rule in every Smaily
 * connector). Smaily's half follows at send time: the queue reads every
 * reminder's contact first, and a contact who unsubscribed in Smaily gets
 * none, in every mode (Queue\Handler\AutomationHandler).
 *
 * - A shopper who ticked the checkout newsletter box on this cart (ordered or
 *   not) gave a new consent, in every mode: SEND.
 * - Consent and checkout opt-in modes (Mode::requiresOptin()): a newsletter
 *   subscriber of the website (a customer, or a guest who signed up) has
 *   consent in the store: SEND. Any other shopper gets the reminder only as a
 *   subscribed contact in Smaily — also one unsubscribed in the store, since
 *   the Smaily list is the one the merchant sends to:
 *   SEND_TO_SUBSCRIBED_CONTACT.
 * - Legitimate-interest mode: every cart (SEND), except a shopper whose
 *   newsletter record on the website is unsubscribed (SKIP_UNSUBSCRIBED).
 *
 * The trigger goes with force_opt_in=false and no status, so a reminder
 * subscribes nobody; the tick subscribes the shopper again only through
 * their own subscription when it syncs.
 */
class ReminderConsent
{
    /** The reminder is sent, also to an address Smaily does not have, unless Smaily has it unsubscribed. */
    public const SEND = 'send';

    /** The reminder is sent only to a contact Smaily has as subscribed. */
    public const SEND_TO_SUBSCRIBED_CONTACT = 'send_to_subscribed_contact';

    /** The shopper unsubscribed in the store: no reminder. */
    public const SKIP_UNSUBSCRIBED = 'skip_unsubscribed';

    public function __construct(
        private readonly Mode $mode,
        private readonly ResourceConnection $resourceConnection,
        private readonly StateManager $stateManager
    ) {
    }

    /**
     * The verdict for each cart of one website, by quote id: one read of the
     * newsletter records and one of the checkout ticks for the whole page.
     *
     * @param int[] $storeIds the website's store views
     * @param array<int, array{email: string, customer_id: int}> $carts by quote id
     * @return array<int, string> self::SEND, SEND_TO_SUBSCRIBED_CONTACT or SKIP_UNSUBSCRIBED
     */
    public function verdicts(int $websiteId, array $storeIds, array $carts): array
    {
        if ($carts === []) {
            return [];
        }
        $statuses = $this->newsletterStatuses($storeIds, $carts);
        $requiresOptin = $this->mode->requiresOptin($websiteId);
        $ticked = $this->stateManager->optedInQuotes(array_keys($carts));

        $verdicts = [];
        foreach ($carts as $quoteId => $cart) {
            $status = $this->statusOf($cart, $statuses);
            if (isset($ticked[$quoteId])) {
                $verdicts[$quoteId] = self::SEND;
            } elseif (!$requiresOptin) {
                $verdicts[$quoteId] = $status === Subscriber::STATUS_UNSUBSCRIBED
                    ? self::SKIP_UNSUBSCRIBED
                    : self::SEND;
            } else {
                $verdicts[$quoteId] = $status === Subscriber::STATUS_SUBSCRIBED
                    ? self::SEND
                    : self::SEND_TO_SUBSCRIBED_CONTACT;
            }
        }

        return $verdicts;
    }

    /**
     * The cart's newsletter status on the website: the record of its address
     * or of its customer. Unsubscribed wins over subscribed when the two
     * records disagree; null without a subscribed or unsubscribed record.
     *
     * @param array{email: string, customer_id: int} $cart
     * @param array{email: array<string, int[]>, customer: array<int, int[]>} $statuses
     */
    private function statusOf(array $cart, array $statuses): ?int
    {
        $found = array_merge(
            $statuses['email'][strtolower($cart['email'])] ?? [],
            $cart['customer_id'] > 0 ? ($statuses['customer'][$cart['customer_id']] ?? []) : []
        );
        if (in_array(Subscriber::STATUS_UNSUBSCRIBED, $found, true)) {
            return Subscriber::STATUS_UNSUBSCRIBED;
        }

        return in_array(Subscriber::STATUS_SUBSCRIBED, $found, true) ? Subscriber::STATUS_SUBSCRIBED : null;
    }

    /**
     * The subscribed and unsubscribed newsletter records of the website's
     * store views that match a cart's address or customer.
     *
     * @param int[] $storeIds
     * @param array<int, array{email: string, customer_id: int}> $carts
     * @return array{email: array<string, int[]>, customer: array<int, int[]>}
     */
    private function newsletterStatuses(array $storeIds, array $carts): array
    {
        $statuses = ['email' => [], 'customer' => []];
        if ($storeIds === []) {
            return $statuses;
        }
        $emails = array_values(array_unique(array_map(
            static fn (array $cart): string => strtolower($cart['email']),
            $carts
        )));
        $customerIds = array_values(array_unique(array_filter(array_column($carts, 'customer_id'))));

        $connection = $this->resourceConnection->getConnection();
        $match = $connection->quoteInto('LOWER(subscriber_email) IN (?)', $emails);
        if ($customerIds !== []) {
            $match .= ' OR ' . $connection->quoteInto('customer_id IN (?)', $customerIds);
        }
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('newsletter_subscriber'),
                ['subscriber_email', 'customer_id', 'subscriber_status']
            )
            ->where('store_id IN (?)', $storeIds)
            ->where('subscriber_status IN (?)', [Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_UNSUBSCRIBED])
            ->where($match);

        foreach ($connection->fetchAll($select) as $row) {
            $status = (int)$row['subscriber_status'];
            $statuses['email'][strtolower((string)$row['subscriber_email'])][] = $status;
            if ((int)$row['customer_id'] > 0) {
                $statuses['customer'][(int)$row['customer_id']][] = $status;
            }
        }

        return $statuses;
    }
}
