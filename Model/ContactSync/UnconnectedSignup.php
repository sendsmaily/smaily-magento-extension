<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ContactSync;

use Magento\Customer\Api\Data\CustomerInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\WebsiteConnections;

/**
 * A contact sync that a website without a Smaily connection would have
 * sent (CON-470), on every channel that syncs a contact — a newsletter
 * subscription or unsubscribe, a customer account save, the checkout
 * opt-in and a guest order: the Log records it as skipped, with the reason
 * WebsiteConnections::SKIPPED_NOT_CONNECTED and the contact the sync would
 * have sent, while another website has a Smaily connection
 * (WebsiteConnections::isUnconnectedWhileAnotherIs()). Only where contact sync
 * would be active for the website if it were connected — its contact sync
 * on and its Contacts settings saved (Config::isContactSyncActive()); any
 * other contact goes unrecorded, as before. Each caller has already
 * decided, by its own rules, that the contact would be synced.
 */
class UnconnectedSignup
{
    public function __construct(
        private readonly Config $config,
        private readonly WebsiteConnections $websiteConnections,
        private readonly SyncDispatcher $dispatcher
    ) {
    }

    /**
     * Whether a contact sync of the store view would be recorded: its
     * website's contact sync would be active, and the website has no Smaily
     * connection while another website has one. A caller checks it before
     * it reads what record() needs.
     */
    public function applies(int $storeId): bool
    {
        $websiteId = $this->dispatcher->websiteId($storeId);

        return $this->config->isContactSyncActive($websiteId)
            && $this->websiteConnections->isUnconnectedWhileAnotherIs($websiteId);
    }

    public function record(string $email, int $storeId, ?bool $isUnsubscribed, ?CustomerInterface $customer): void
    {
        if ($email === '' || !$this->applies($storeId)) {
            return;
        }

        $this->dispatcher->recordSkippedContactSync(
            $email,
            $storeId,
            $isUnsubscribed,
            $customer,
            WebsiteConnections::SKIPPED_NOT_CONNECTED
        );
    }
}
