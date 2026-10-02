<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Cron;

use Magento\Framework\FlagManager;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\ConsentSource;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Health\QueueHealth;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Proactive health notices (mirrors the Woo NotificationManager):
 * - Campaign Intelligence unreachable for over an hour -> major notice once
 *   per incident.
 * - Failed queue rows exceed a threshold in 24h -> minor notice at most once
 *   a day.
 * - Browse tracking on without Magento cookie restriction mode -> minor
 *   notice once, again after the condition has cleared and come back: the
 *   tracker then has no consent source the server can see and sends
 *   nothing unless the store added the consent override (PRO-3664).
 *
 * The flags and the failed-row query are shared with the admin dashboard
 * (ViewModel\Adminhtml\DashboardData) so both surfaces tell the same story.
 */
class HealthCheck
{
    public const FLAG_ENGINE_DOWN_SINCE = 'smaily_connect_engine_down_since';
    public const FLAG_ENGINE_NOTIFIED = 'smaily_connect_engine_down_notified';
    public const FLAG_CONSENT_SOURCE_NOTIFIED = 'smaily_connect_consent_source_notified';
    private const FLAG_TENANT_INACTIVE_NOTIFIED = 'smaily_connect_engine_tenant_inactive_notified';
    private const FLAG_FAILURES_NOTIFIED_AT = 'smaily_connect_failures_notified_at';

    private const ENGINE_DOWN_NOTIFY_SECONDS = 3600;
    private const FAILED_EVENTS_THRESHOLD = 25;

    public function __construct(
        private readonly Settings $settings,
        private readonly Client $client,
        private readonly FlagManager $flagManager,
        private readonly NotifierInterface $notifier,
        private readonly QueueHealth $queueHealth,
        private readonly DateTime $dateTime,
        private readonly Logger $logger,
        private readonly ConsentSource $consentSource
    ) {
    }

    public function execute(): void
    {
        $this->checkEngine();
        $this->checkFailureVolume();
        $this->checkConsentSource();
    }

    private function checkEngine(): void
    {
        if (!$this->settings->isConnected()) {
            return;
        }

        try {
            // The ping is also the re-check that clears a remembered refusal:
            // Engine\Client clears it on any authenticated call that answers.
            $this->client->ping();
            $this->flagManager->deleteFlag(self::FLAG_ENGINE_DOWN_SINCE);
            $this->flagManager->deleteFlag(self::FLAG_ENGINE_NOTIFIED);
            $this->flagManager->deleteFlag(self::FLAG_TENANT_INACTIVE_NOTIFIED);
        } catch (EngineException $exception) {
            if ($this->settings->isRefused()) {
                $this->notifyTenantInactive();

                return;
            }

            $now = $this->dateTime->gmtTimestamp();
            $downSince = (int)$this->flagManager->getFlagData(self::FLAG_ENGINE_DOWN_SINCE);
            if ($downSince === 0) {
                $this->flagManager->saveFlag(self::FLAG_ENGINE_DOWN_SINCE, $now);

                return;
            }

            $alreadyNotified = (bool)$this->flagManager->getFlagData(self::FLAG_ENGINE_NOTIFIED);
            if (!$alreadyNotified && ($now - $downSince) >= self::ENGINE_DOWN_NOTIFY_SECONDS) {
                $this->notifier->addMajor(
                    (string)__('Smaily Campaign Intelligence is unreachable'),
                    (string)__(
                        'The engine has not responded for over an hour (%1). Data is queued locally and will sync when the connection recovers.',
                        $exception->getMessage()
                    )
                );
                $this->flagManager->saveFlag(self::FLAG_ENGINE_NOTIFIED, 1);
                $this->logger->error('Engine down for over an hour', ['error' => $exception->getMessage()]);
            }
        }
    }

    /**
     * A deactivated account is a verdict, not an outage — the engine answers
     * fine, waiting changes nothing and only Smaily can lift it. So the
     * engine-down clock is dropped and the notice promises no recovery
     * (PRO-2451; the misleading "will sync when it recovers" wording is what
     * PRO-1953 recorded). Once per incident, like the down notice.
     */
    private function notifyTenantInactive(): void
    {
        $this->flagManager->deleteFlag(self::FLAG_ENGINE_DOWN_SINCE);
        $this->flagManager->deleteFlag(self::FLAG_ENGINE_NOTIFIED);
        if ((bool)$this->flagManager->getFlagData(self::FLAG_TENANT_INACTIVE_NOTIFIED)) {
            return;
        }

        // The same sentence pair the admin surfaces carry, said once.
        $this->notifier->addMajor(
            (string)__('Your Campaign Intelligence account is not active'),
            (string)__(
                'Campaign Intelligence has stopped accepting data from this store, so product, customer and order data is no longer being sent and recommendations will go stale. Waiting will not fix it — the account has to be made active again on the Smaily side. Your Smaily email sending is unaffected; everything queued here waits and is sent once the account is active.'
            )
        );
        $this->flagManager->saveFlag(self::FLAG_TENANT_INACTIVE_NOTIFIED, 1);
        $this->logger->error('Campaign Intelligence account is not active', [
            'refused_at' => $this->settings->getRefusedAt(),
        ]);
    }

    private function checkFailureVolume(): void
    {
        $now = $this->dateTime->gmtTimestamp();
        $lastNotified = (int)$this->flagManager->getFlagData(self::FLAG_FAILURES_NOTIFIED_AT);
        if ($now - $lastNotified < 86400) {
            return;
        }

        $failed = $this->queueHealth->failedSince(86400);

        if ($failed >= self::FAILED_EVENTS_THRESHOLD) {
            $this->notifier->addMinor(
                (string)__('Smaily Connect: %1 events failed in the last 24 hours', $failed),
                (string)__('Review the log under Marketing > Smaily Connect > Log and retry the failed rows.')
            );
            $this->flagManager->saveFlag(self::FLAG_FAILURES_NOTIFIED_AT, $now);
        }
    }

    private function checkConsentSource(): void
    {
        if (!$this->settings->isBrowseTrackingEnabled()
            || $this->consentSource->isCookieRestrictionOnEverywhere()
        ) {
            $this->flagManager->deleteFlag(self::FLAG_CONSENT_SOURCE_NOTIFIED);

            return;
        }
        if ((bool)$this->flagManager->getFlagData(self::FLAG_CONSENT_SOURCE_NOTIFIED)) {
            return;
        }

        $this->notifier->addMinor(
            (string)__('Smaily Connect: browse tracking collects nothing until a consent source is connected'),
            (string)__(
                'Browse tracking is on, but Magento’s cookie restriction mode is off, so the tracker sends no browse events — it has no visitor consent to go by. Connect a consent source in one of two ways: switch on Cookie Restriction Mode under Stores > Configuration > General > Web > Default Cookie Settings, or connect your own cookie consent tool with the consent override described in the User Guide (Connecting your cookie consent tool). If your store already has the override, mark this message as read.'
            ),
            ConsentSource::GUIDE_URL
        );
        $this->flagManager->saveFlag(self::FLAG_CONSENT_SOURCE_NOTIFIED, 1);
    }
}
