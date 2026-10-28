<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Smaily\Connect\Model\Logger\Logger;

/**
 * A row a Campaign Intelligence import skips because its payload cannot be
 * built from what Magento stores for it (CON-227): Magento throws an
 * InvalidArgumentException for a stored value it cannot unserialize, and a
 * LocalizedException for an entity it cannot find (a store view that no
 * longer exists, say). One such row used to stop the whole import; now the
 * import skips it, counts it in the job's failed_count — the import card
 * reports it as skipped — and writes it to the extension's log
 * (var/log/smaily_connect.log). The entry names the job, the row's id and
 * the error class and where it was thrown, never the error text, which can
 * quote a customer's data (as the WooCommerce plugin records a failed
 * import). A database or programming error is neither kind, and still stops
 * the import.
 */
class SkippedRow
{
    public function __construct(
        private readonly Logger $logger
    ) {
    }

    public function record(Job $job, int $entityId, \Throwable $exception): void
    {
        $this->logger->error('Campaign Intelligence import skipped a row Magento could not read', [
            'job_id' => (int)$job->getId(),
            'type' => $job->getJobType(),
            'entity_id' => $entityId,
            'error' => ErrorLocation::of($exception),
        ]);
    }
}
