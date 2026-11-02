<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Observer\Engine;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Newsletter\Model\Subscriber;
use Smaily\Connect\Model\Engine\CustomerIngest;

/**
 * A customer's newsletter subscription enters a status the contact sync
 * sends (subscribed or unsubscribed, from none or a pending confirmation),
 * newsletter_subscriber_save_after: under the website's sync mode the
 * customer may now be in the Campaign Intelligence customer scope's group
 * (a), so the account's row is queued when it is (Engine\CustomerIngest::
 * joined(), CON-266). A subscription saves no customer, so the account
 * save does not cover it. A move between subscribed and unsubscribed
 * changes no group and queues nothing; a guest subscription has no
 * account to send.
 *
 * Not behind the ReconcileGuard, like the other engine subscriber
 * observers: a status Smaily's consent mirror writes is the subscriber's
 * status too, and the scope reads it.
 */
class SubscriberJoined implements ObserverInterface
{
    private const SENT_STATUSES = [Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_UNSUBSCRIBED];

    public function __construct(
        private readonly CustomerIngest $customerIngest
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $subscriber = $observer->getEvent()->getData('subscriber');
        if (!$subscriber instanceof Subscriber
            || !$subscriber->isStatusChanged()
            || !in_array((int)$subscriber->getStatus(), self::SENT_STATUSES, true)
            || in_array((int)$subscriber->getOrigData('subscriber_status'), self::SENT_STATUSES, true)
        ) {
            return;
        }

        $this->customerIngest->joined((int)$subscriber->getCustomerId());
    }
}
