<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Plugin;

use Magento\Newsletter\Model\Subscriber;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;

/**
 * Suppresses Magento's own newsletter confirmation-success and
 * unsubscription emails so Smaily automations can own that communication.
 *
 * Uses the legacy-proven setImportMode(true) trick: Subscriber::send*Email()
 * returns early in import mode. The double-opt-in confirmation REQUEST email
 * is deliberately never suppressed — it is part of the consent flow.
 */
class SuppressNewsletterEmails
{
    public function __construct(
        private readonly Config $config,
        private readonly SyncDispatcher $dispatcher
    ) {
    }

    public function beforeSendConfirmationSuccessEmail(Subscriber $subject): void
    {
        $this->suppress($subject);
    }

    public function beforeSendUnsubscriptionEmail(Subscriber $subject): void
    {
        $this->suppress($subject);
    }

    private function suppress(Subscriber $subscriber): void
    {
        $storeId = (int)$subscriber->getStoreId();
        $websiteId = $this->dispatcher->websiteId($storeId);

        if ($this->config->isConnected($storeId ?: null) && $this->config->suppressOptinEmails($websiteId)) {
            $subscriber->setImportMode(true);
        }
    }
}
