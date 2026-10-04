<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Framework\Phrase;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;

/**
 * Why a Campaign Intelligence import cannot start now: every engine-bound
 * import (catalog, customers, orders) needs the connection — without it
 * there is nowhere to send the data (PRO-1969, PRO-3742). The admin import
 * endpoint and smaily:backfill:start both ask here before starting a job;
 * the contacts import goes to Smaily and is never refused here.
 */
class EngineImportGuard
{
    public function __construct(
        private readonly EngineSettings $engineSettings
    ) {
    }

    /**
     * The reason an import of this type does not start, or null when it may.
     */
    public function refusal(string $jobType): ?Phrase
    {
        if ((Job::TYPE_TARGETS[$jobType] ?? null) !== Job::TARGET_ENGINE || $this->engineSettings->isConnected()) {
            return null;
        }

        return match ($jobType) {
            Job::TYPE_CATALOG => __('Campaign Intelligence is not connected, so there is nowhere to send the catalog.'),
            Job::TYPE_CUSTOMERS => __(
                'Campaign Intelligence is not connected, so there is nowhere to send the customer data.'
            ),
            default => __('Campaign Intelligence is not connected, so there is nowhere to send the order data.'),
        };
    }
}
