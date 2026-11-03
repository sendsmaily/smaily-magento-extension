<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Framework\Phrase;
use Smaily\Connect\Model\Engine\Connection;

/**
 * Why a Campaign Intelligence import cannot start now: every engine-bound
 * import (catalog, customers, orders) needs the connection — without it
 * there is nowhere to send the data (PRO-1969, PRO-3742). ImportStarter —
 * the start the admin import endpoint and smaily:backfill:start share —
 * asks here before starting a job, for the connection the import is for
 * (CON-237); the contacts import goes to Smaily and is never refused here.
 */
class EngineImportGuard
{
    /**
     * The reason an import of this type does not start, or null when it may.
     *
     * @param string $target The type's target (Job::TYPE_TARGETS), as the caller resolved it.
     * @param Connection|null $connection The connection an engine import is for; null when no
     *     connection serves the website asked for.
     */
    public function refusal(string $jobType, string $target, ?Connection $connection): ?Phrase
    {
        if ($target !== Job::TARGET_ENGINE || ($connection !== null && $connection->settings()->isConnected())) {
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
