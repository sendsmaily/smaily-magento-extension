<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Report;

/**
 * Sends a `disconnected` or `deactivated` installation report now, once,
 * without a retry (CON-234; contract installation-report-v1 "Events"):
 * afterwards the installation no longer holds the credentials, or no longer
 * runs. Whatever happens, the caller goes on with the disconnect or the
 * removal: it never throws, and it answers one line for the caller's log.
 *
 * The removal (Setup\Uninstall) builds it without the module's di.xml, so
 * it logs nothing itself (the module's Logger needs that di.xml).
 */
class EventReport
{
    public const NOT_SENT = 'not_sent';

    public function __construct(
        private readonly ReportBuilder $builder,
        private readonly ReportSender $sender
    ) {
    }

    /**
     * @param string $event ReportBuilder::EVENT_DISCONNECTED or EVENT_DEACTIVATED
     * @param string[] $removedTenantIds engine stores a `disconnected` event removes
     * @param Credentials|null $previousMain the main account before a save removed accounts
     * @return string what happened, for a log line: NOT_SENT or the SendResult summary
     */
    public function send(string $event, array $removedTenantIds = [], ?Credentials $previousMain = null): string
    {
        try {
            $report = $this->builder->build($event, '', $removedTenantIds, $previousMain);
            if ($report === null) {
                return self::NOT_SENT;
            }

            return $this->sender->send($report->body, $report->credentials)->summary();
        } catch (\Throwable) {
            return self::NOT_SENT;
        }
    }
}
