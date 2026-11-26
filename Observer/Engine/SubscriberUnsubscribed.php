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
 * An unsubscribe from marketing also stops profiling (PRO-3578, Woo F3-31;
 * Erkki 2026-10-02) — newsletter_subscriber_save_after.
 *
 * Deliberately NOT behind the ReconcileGuard: an unsubscribe Smaily's consent
 * mirror writes onto the subscriber is the shopper's unsubscribe too.
 * Subscribing again turns profiling back on (SubscriberResubscribed), unless
 * the shopper also opted out of profiling on its own (PRO-3594).
 *
 * A status change is read from the status saved against the status loaded,
 * not from the subscriber's status-changed flag (CON-436, as the contact
 * sync since PRO-4322): code that sets the status field directly leaves the
 * flag unset, and setStatus() sets it for the unchanged status when the
 * loaded value is a string, so the flag both lost changes and sent repeated
 * saves again.
 */
class SubscriberUnsubscribed implements ObserverInterface
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
            || (int)$subscriber->getStatus() !== Subscriber::STATUS_UNSUBSCRIBED
            || !$this->settings->isConnected()
        ) {
            return;
        }

        $this->profilingConsent->optOutOnUnsubscribe((string)$subscriber->getEmail());
    }
}
