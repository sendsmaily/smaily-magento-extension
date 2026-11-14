<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Report;

use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Decides when an installation report snapshot goes (CON-234; contract
 * installation-report-v1 "When an installation sends a report"), as the
 * WooCommerce plugin's InstallationReport (CON-290). ReportBuilder makes the
 * body and ReportSender sends it.
 *
 * Only while a Smaily marketing account is connected: with none, nothing is
 * scheduled or sent, and what was scheduled is forgotten.
 *
 * Snapshots go from the cron job `smaily_installation_report`
 * (Cron\SendInstallationReport, every minute, at most one report a run),
 * never from a page request:
 *   - `setup_saved` DEBOUNCE_SECONDS after a save of the setup; more saves
 *     in that window add no second report;
 *   - `daily` 24 hours after the last snapshot attempt; the first at a
 *     random moment within 24 hours, so stores spread across the day.
 * A 429, a 5xx, a timeout or a network error is tried again after 60, 120
 * and 240 seconds plus 0–30 seconds, or after a longer Retry-After; a
 * Retry-After over an hour ends the try. Any other answer is logged once
 * and not tried again. A failed report is never queued: the next one
 * carries the current state.
 *
 * The schedule is the flag FLAG_CODE: `setup_saved_at`, `daily_at` and
 * `retry` ({trigger, attempt, at}). Nothing it does is shown to the
 * merchant; a log line carries the HTTP status and the service's error
 * code, never a message, a body or a credential.
 */
class InstallationReport
{
    public const FLAG_CODE = 'smaily_connect_installation_report';

    public const DEBOUNCE_SECONDS = 300;
    public const DAY_SECONDS = 86400;

    /** The waits before the three retries. */
    public const RETRY_DELAYS = [60, 120, 240];
    public const RETRY_JITTER_SECONDS = 30;
    public const MAX_RETRY_AFTER_SECONDS = 3600;

    public function __construct(
        private readonly ReportBuilder $builder,
        private readonly ReportSender $sender,
        private readonly FlagManager $flagManager,
        private readonly DateTime $dateTime,
        private readonly Logger $logger
    ) {
    }

    /**
     * A save of the setup: one `setup_saved` report DEBOUNCE_SECONDS from
     * now, unless one already waits. Never throws: a save never fails on
     * its report.
     */
    public function setupSaved(): void
    {
        try {
            if ($this->builder->mainCredentials() === null) {
                return;
            }
            $state = $this->state();
            if (($state['setup_saved_at'] ?? null) !== null) {
                return;
            }
            $state['setup_saved_at'] = $this->now() + self::DEBOUNCE_SECONDS;
            $this->save($state);
        } catch (\Throwable $exception) {
            $this->logger->error('Installation report not scheduled', ['error' => get_class($exception)]);
        }
    }

    /**
     * The cron run: send the snapshot that is due — a `setup_saved` one
     * first, then a retry, then the daily one — and set what comes next.
     */
    public function run(): void
    {
        if ($this->builder->mainCredentials() === null) {
            // Nothing is reported without a connected account; a later
            // connection starts the schedule anew.
            if ($this->state() !== []) {
                $this->save([]);
            }

            return;
        }

        $state = $this->state();
        $now = $this->now();
        $dailyArmed = ($state['daily_at'] ?? null) !== null;
        if (!$dailyArmed) {
            $state['daily_at'] = $now + $this->random(0, self::DAY_SECONDS);
        }

        $retry = is_array($state['retry'] ?? null) ? $state['retry'] : null;
        $attempt = 0;
        if (($state['setup_saved_at'] ?? null) !== null && (int)$state['setup_saved_at'] <= $now) {
            $trigger = ReportBuilder::TRIGGER_SETUP_SAVED;
            $state['setup_saved_at'] = null;
        } elseif ($retry !== null && (int)($retry['at'] ?? 0) <= $now) {
            $trigger = (string)($retry['trigger'] ?? ReportBuilder::TRIGGER_DAILY);
            $attempt = (int)($retry['attempt'] ?? 1);
        } elseif ((int)$state['daily_at'] <= $now) {
            $trigger = ReportBuilder::TRIGGER_DAILY;
        } else {
            $this->save($state);

            return;
        }

        // The attempt is recorded before it is made, so a run that dies
        // inside it never sends the same report every minute. The daily
        // report is due 24 hours after this attempt — except the first,
        // armed in this run at its random moment.
        $state['retry'] = null;
        if ($dailyArmed || $trigger === ReportBuilder::TRIGGER_DAILY) {
            $state['daily_at'] = $now + self::DAY_SECONDS;
        }
        $this->save($state);

        $report = $this->builder->build(ReportBuilder::EVENT_SNAPSHOT, $trigger);
        if ($report === null) {
            return;
        }
        $result = $this->sender->send($report->body, $report->credentials);
        if ($result->isAccepted()) {
            return;
        }

        $context = ['trigger' => $trigger, 'attempt' => $attempt, 'result' => $result->summary()];
        if ($result->retry && $attempt < count(self::RETRY_DELAYS)
            && $result->retryAfter <= self::MAX_RETRY_AFTER_SECONDS
        ) {
            $wait = max(
                self::RETRY_DELAYS[$attempt] + $this->random(0, self::RETRY_JITTER_SECONDS),
                $result->retryAfter
            );
            $state['retry'] = ['trigger' => $trigger, 'attempt' => $attempt + 1, 'at' => $now + $wait];
            $this->save($state);
            $this->logger->info('Installation report not taken in; it is tried again', $context);

            return;
        }

        $this->logger->error('Installation report not taken in', $context);
    }

    protected function random(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    private function now(): int
    {
        return (int)$this->dateTime->gmtTimestamp();
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $data = $this->flagManager->getFlagData(self::FLAG_CODE);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save(array $state): void
    {
        $this->flagManager->saveFlag(self::FLAG_CODE, $state);
    }
}
