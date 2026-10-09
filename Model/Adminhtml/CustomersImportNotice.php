<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\UrlInterface;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;

/**
 * The admin system message that asks the merchant to start the customers
 * import (PRO-4007). Storefront recommendations ask Campaign Intelligence
 * by the Magento customer id, which an account gets there from the
 * customers import or its next save; connecting starts that import
 * (Model/Backfill/ImportsOnConnect, PRO-3790), but a store connected
 * before then, or one whose import was cancelled or failed, may never
 * have run it. Nothing starts it automatically: the merchant decides when
 * the data goes.
 *
 * Shown on every admin page while Campaign Intelligence is connected (one
 * tenant per installation, Engine\Settings::isConnected() — the same gate
 * as EngineImportGuard) and no customers import has ever completed;
 * hidden while one is queued or running, unless it has stalled
 * (JobManager::isStalled(), the card's Stalled) — a stalled import does
 * not move on by itself, and the card's Run again starts a fresh one.
 * Magento evaluates it on each page, so it disappears once the import
 * completes. Registered in etc/adminhtml/di.xml.
 */
class CustomersImportNotice implements MessageInterface
{
    public function __construct(
        private readonly EngineSettings $engineSettings,
        private readonly JobManager $jobManager,
        private readonly UrlInterface $urlBuilder,
        private readonly Escaper $escaper
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getIdentity()
    {
        return 'smaily_connect_customers_import';
    }

    /**
     * @inheritDoc
     */
    public function isDisplayed()
    {
        return $this->engineSettings->isConnected()
            && !$this->jobManager->hasCompleted(Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE)
            && (
                $this->jobManager->findActive(Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID) === null
                || $this->jobManager->isStalled(Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE)
            );
    }

    /**
     * @inheritDoc
     */
    public function getText()
    {
        $url = $this->urlBuilder->getUrl('smaily_connect/settings', ['_query' => ['tab' => 'intelligence']]);

        return (string)__(
            'Smaily Connect: the customers import has never completed, so Campaign Intelligence may not know your existing customer accounts and the Smaily recommendations widget may show signed-in customers nothing. %1Start the customers import%2: press Start import, or Run again, on the Customers card under Marketing > Smaily Connect > Settings > Intelligence.',
            '<a href="' . $this->escaper->escapeUrl($url) . '">',
            '</a>'
        );
    }

    /**
     * @inheritDoc
     */
    public function getSeverity()
    {
        return self::SEVERITY_MAJOR;
    }
}
