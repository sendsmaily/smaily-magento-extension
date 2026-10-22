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
use Smaily\Connect\Model\ContactSync\AdminSubscriberDelete;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Privacy\ProfilingConsent;

/**
 * A subscriber row deleted in the admin's Newsletter Subscribers grid stops
 * profiling, as an unsubscribe in the store does (CON-187, SubscriberUnsubscribed)
 * — newsletter_subscriber_delete_after. The delete counts as an unsubscribe
 * in every way (Erkki, 2026-10-22): Observer\SubscriberDeleteAfter carries it
 * to Smaily, this observer to Campaign Intelligence.
 *
 * Only while the grid's Delete action runs (AdminSubscriberDelete): a
 * customer account's deletion also deletes its subscriber row, and that
 * opts nobody out. A row already unsubscribed opted out when it unsubscribed.
 */
class SubscriberDeleted implements ObserverInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AdminSubscriberDelete $adminSubscriberDelete,
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
            || !$this->adminSubscriberDelete->isActive()
            || (int)$subscriber->getStatus() === Subscriber::STATUS_UNSUBSCRIBED
            || !$this->settings->isConnected()
        ) {
            return;
        }

        $this->profilingConsent->optOutOnUnsubscribe((string)$subscriber->getEmail());
    }
}
