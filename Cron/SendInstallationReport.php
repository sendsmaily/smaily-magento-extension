<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Cron;

use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Report\InstallationReport;

/**
 * Sends the installation report snapshot that is due (CON-234): a
 * `setup_saved` one after a save, a retry, or the daily one — see
 * Model\Report\InstallationReport. A failure never reaches cron's log as an
 * error of the store: it is one line in the module's log.
 */
class SendInstallationReport
{
    public function __construct(
        private readonly InstallationReport $installationReport,
        private readonly Logger $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->installationReport->run();
        } catch (\Throwable $exception) {
            $this->logger->error('Installation report run failed', ['error' => get_class($exception)]);
        }
    }
}
