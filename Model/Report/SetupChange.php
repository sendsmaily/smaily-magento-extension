<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Report;

use Smaily\Connect\Model\Logger\Logger;

/**
 * What a save of the setup means for the installation report (CON-234):
 * call before() ahead of the save and after() once it is written.
 *
 * A save that removes a Smaily account the installation held — a cleared
 * Transactional account, a language account mode A no longer uses, an
 * account replaced by another — sends a `disconnected` event at once,
 * carrying the connections that remain. It is authenticated with the main
 * account after the save, or, when no marketing account remains, with the
 * main account captured before the save, the last credentials the
 * installation held for it. A save that went through also schedules a
 * `setup_saved` snapshot. Neither ever stops the save.
 */
class SetupChange
{
    public function __construct(
        private readonly ReportBuilder $builder,
        private readonly EventReport $eventReport,
        private readonly InstallationReport $installationReport,
        private readonly Logger $logger
    ) {
    }

    /**
     * The installation's accounts before the save.
     *
     * @return array{accounts: string[], main: ?Credentials}
     */
    public function before(): array
    {
        try {
            return ['accounts' => $this->builder->accountKeys(), 'main' => $this->builder->mainCredentials()];
        } catch (\Throwable) {
            return ['accounts' => [], 'main' => null];
        }
    }

    /**
     * @param array{accounts: string[], main: ?Credentials} $before what before() answered
     * @param bool $saved whether the save went through
     */
    public function after(array $before, bool $saved = true): void
    {
        try {
            $removed = array_diff($before['accounts'], $this->builder->accountKeys());
        } catch (\Throwable) {
            $removed = [];
        }
        if ($removed !== []) {
            $outcome = $this->eventReport->send(ReportBuilder::EVENT_DISCONNECTED, [], $before['main']);
            $this->logger->info('Installation report: a save removed a Smaily account', [
                'removed' => count($removed),
                'outcome' => $outcome,
            ]);
        }
        if ($saved) {
            $this->installationReport->setupSaved();
        }
    }
}
