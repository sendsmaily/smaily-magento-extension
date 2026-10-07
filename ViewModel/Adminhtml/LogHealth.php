<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Model\Health\QueueHealth;

/**
 * Failed-deliveries banner data for the Log page — the same QueueHealth
 * query the HealthCheck cron and the dashboard use, so all three surfaces
 * always report the same number.
 */
class LogHealth implements ArgumentInterface
{
    public function __construct(
        private readonly QueueHealth $queueHealth
    ) {
    }

    public function getFailedLast24h(): int
    {
        return $this->queueHealth->failedSince(86400);
    }
}
