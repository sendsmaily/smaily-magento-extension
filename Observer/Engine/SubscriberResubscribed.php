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
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Privacy\ProfilingConsent;

/**
 * Subscribing to marketing again switches profiling back on when the
 * opt-out came only from unsubscribing (PRO-3594, Erkki 2026-10-02) —
 * newsletter_subscriber_save_after. A profiling opt-out the shopper made on
 * its own stays; ProfilingConsent tells the two apart.
 *
 * Deliberately NOT behind the ReconcileGuard, like SubscriberUnsubscribed: a
 * subscription Smaily's consent mirror writes onto the subscriber is the
 * shopper's subscription too.
 *
 * A status change is read from the status saved against the status loaded,
 * not from the subscriber's status-changed flag (CON-436, as the contact
 * sync since PRO-4322): code that sets the status field directly leaves the
 * flag unset, and setStatus() sets it for the unchanged status when the
 * loaded value is a string, so the flag both lost changes and sent repeated
 * saves again.
 */
class SubscriberResubscribed implements ObserverInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ProfilingConsent $profilingConsent
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $subscriber = $observer->getEvent()->getData('subscriber');
        if (!$subscriber instanceof Subscriber
            || !$subscriber->dataHasChangedFor('subscriber_status')
            || (int)$subscriber->getStatus() !== Subscriber::STATUS_SUBSCRIBED
            || !$this->settings->isConnected()
        ) {
            return;
        }

        $this->profilingConsent->optInOnResubscribe((string)$subscriber->getEmail());
    }
}
