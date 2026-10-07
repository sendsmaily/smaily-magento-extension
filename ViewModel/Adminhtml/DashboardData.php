<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Cron\HealthCheck;
use Smaily\Connect\Model\Adminhtml\DashboardStats;
use Smaily\Connect\Model\Adminhtml\SetupGuard;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\CatalogManifest;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Health\QueueHealth;
use Smaily\Connect\Model\Log\StatusPill;
use Smaily\Connect\Ui\Component\QueueStatusOptions;

/**
 * Operational dashboard data. The verdict reuses the HealthCheck cron's
 * state (engine-down flag) and query (failed rows in 24h) so the dashboard
 * and the admin notifications always agree.
 */
class DashboardData implements ArgumentInterface
{
    public const VERDICT_INCOMPLETE = 'incomplete';
    public const VERDICT_DISCONNECTED = 'disconnected';
    public const VERDICT_DEGRADED = 'degraded';
    public const VERDICT_OK = 'ok';

    /** Nights in a row without a nightly product list before the Dashboard says so (PRO-3914). */
    public const UNSENT_PRODUCT_LIST_NIGHTS = 3;

    private ?int $failed24h = null;
    private ?bool $smailyConnected = null;
    private ?bool $engineRefused = null;
    private ?bool $engineDown = null;

    /** @var array{nights: int, reason: string}|false|null false once read and nothing to say */
    private array|false|null $unsentProductList = null;

    public function __construct(
        private readonly Config $config,
        private readonly EngineSettings $engineSettings,
        private readonly SetupGuard $setupGuard,
        private readonly QueueHealth $queueHealth,
        private readonly DashboardStats $stats,
        private readonly FlagManager $flagManager,
        private readonly VerifiedCredentials $verifiedCredentials,
        private readonly WebsiteContext $websiteContext,
        private readonly StatusPill $statusPill,
        private readonly QueueStatusOptions $statusOptions,
        private readonly DateTime $dateTime
    ) {
    }

    public function isSetupCompleted(): bool
    {
        return $this->setupGuard->isSetupCompleted();
    }

    /**
     * Whether Smaily accepted the saved credentials at the last real check
     * (PRO-3560) — read for the same account as the Connection status, the
     * one saved for the target website (PRO-3719), so the two cannot
     * disagree.
     */
    public function isSmailyConnected(): bool
    {
        return $this->smailyConnected ??= $this->verifiedCredentials->isWebsiteVerified(
            $this->websiteContext->getWebsiteId()
        );
    }

    /**
     * Whether Smaily's last answer for the saved credentials was that the
     * account's package does not include API access (PRO-3579) — the reason
     * the store is not connected, in place of refused credentials.
     */
    public function isSmailyPlanBlocked(): bool
    {
        return $this->verifiedCredentials->isWebsitePlanBlocked($this->websiteContext->getWebsiteId());
    }

    public function getSmailySubdomain(): string
    {
        return $this->config->getWebsiteSubdomain($this->websiteContext->getWebsiteId());
    }

    public function isEngineConnected(): bool
    {
        return $this->engineSettings->isConnected();
    }

    public function getEngineTenantName(): string
    {
        return $this->engineSettings->getTenantName() ?: $this->engineSettings->getTenantId();
    }

    public function isBrowseTrackingEnabled(): bool
    {
        return $this->engineSettings->isBrowseTrackingEnabled();
    }

    /**
     * Whether the target website sells on a separate storefront: a
     * Storefront URL is saved, as Settings > Intelligence reads it
     * (PRO-3918).
     */
    public function hasSeparateStorefront(): bool
    {
        return $this->config->getStorefrontUrl($this->websiteContext->getStoreId()) !== '';
    }

    /**
     * Whether Campaign Intelligence has refused this account outright
     * (contract §2) — a verdict, not an outage, so the dashboard must not
     * report it as one (PRO-2451). Same name, same meaning as on WizardData.
     */
    public function isEngineRefused(): bool
    {
        return $this->engineRefused ??= $this->engineSettings->isRefused();
    }

    /**
     * Whether the HealthCheck cron currently sees the engine as unreachable.
     */
    public function isEngineDown(): bool
    {
        return $this->engineDown ??= $this->isEngineConnected()
            && (int)$this->flagManager->getFlagData(HealthCheck::FLAG_ENGINE_DOWN_SINCE) > 0;
    }

    /**
     * The nightly product list (PRO-3914) has not gone out for
     * UNSENT_PRODUCT_LIST_NIGHTS nights or more in a row: how many, and why
     * the last night's did not, in the admin's language. Null when it went
     * out since; while Campaign Intelligence is not connected or refuses the
     * account (other signals cover those); and for a connection younger than
     * that many nights.
     *
     * @return array{nights: int, reason: string}|null
     */
    public function getUnsentProductList(): ?array
    {
        if ($this->unsentProductList === null) {
            $this->unsentProductList = $this->readUnsentProductList() ?? false;
        }

        return $this->unsentProductList ?: null;
    }

    /**
     * @return array{nights: int, reason: string}|null
     */
    private function readUnsentProductList(): ?array
    {
        if (!$this->isEngineConnected() || $this->isEngineRefused()) {
            return null;
        }
        $unsent = $this->flagManager->getFlagData(CatalogManifest::FLAG_UNSENT);
        $nights = is_array($unsent) ? (int)($unsent['nights'] ?? 0) : 0;
        if ($nights < self::UNSENT_PRODUCT_LIST_NIGHTS) {
            return null;
        }
        $connectedAt = strtotime($this->engineSettings->getIssuedAt());
        if ($connectedAt !== false
            && $connectedAt > $this->dateTime->gmtTimestamp() - self::UNSENT_PRODUCT_LIST_NIGHTS * 86400
        ) {
            return null;
        }

        // The over-the-limit night in its failed Log row's own words.
        $reasons = [
            CatalogManifest::REASON_IMPORT => __(
                'Last night the catalog import was running or waiting to start, and the list waits for it to finish.'
            ),
            CatalogManifest::REASON_QUEUE => __(
                'Last night product changes were still waiting to be sent in the Log, and the list waits for them.'
            ),
            CatalogManifest::REASON_BUILD => __(
                'Last night the list could not be built; var/log/smaily_connect.log on the server says why.'
            ),
            CatalogManifest::REASON_TOO_MANY => __(CatalogManifest::TOO_MANY_PRODUCTS),
            CatalogManifest::REASON_FAILED => __(
                'Last night Campaign Intelligence did not take the list;'
                . ' its failed catalog_manifest row in the Log says why.'
            ),
        ];

        return [
            'nights' => $nights,
            'reason' => (string)($reasons[(string)($unsent['reason'] ?? '')] ?? ''),
        ];
    }

    /**
     * The dashboard connection strip's Campaign Intelligence row — sub-line,
     * pill variant and pill label. All three are read off one engine state,
     * so they can never tell three different stories.
     *
     * @return array{sub: string, variant: string, label: string}
     */
    public function getEngineStateLabels(): array
    {
        if ($this->isEngineRefused()) {
            return [
                'sub' => (string)__('Account not active on the Smaily side'),
                'variant' => 'failed',
                'label' => (string)__('Not active'),
            ];
        }
        if ($this->isEngineDown()) {
            return [
                'sub' => (string)__('Currently unreachable'),
                'variant' => 'pending',
                'label' => (string)__('Unreachable'),
            ];
        }
        if ($this->isEngineConnected()) {
            return [
                'sub' => (string)__('Connected (%1)', $this->getEngineTenantName()),
                'variant' => 'active',
                'label' => (string)__('On'),
            ];
        }

        return [
            'sub' => (string)__('Not connected'),
            'variant' => 'off',
            'label' => (string)__('Off'),
        ];
    }

    public function getFailedLast24h(): int
    {
        if ($this->failed24h === null) {
            $this->failed24h = $this->queueHealth->failedSince(86400);
        }

        return $this->failed24h;
    }

    /**
     * One-word health state: incomplete | disconnected | degraded | ok.
     * A Smaily connection that Smaily has not accepted outranks failures,
     * because it explains them (PRO-3560).
     */
    public function getVerdict(): string
    {
        if (!$this->isSetupCompleted()) {
            return self::VERDICT_INCOMPLETE;
        }
        if (!$this->isSmailyConnected()) {
            return self::VERDICT_DISCONNECTED;
        }
        if ($this->isEngineRefused()
            || $this->getFailedLast24h() > 0
            || $this->isEngineDown()
            || $this->getUnsentProductList() !== null
        ) {
            return self::VERDICT_DEGRADED;
        }

        return self::VERDICT_OK;
    }

    public function getContactSyncsDelivered(): int
    {
        return $this->stats->contactSyncsDelivered();
    }

    public function getCatalogItemsDelivered(): int
    {
        return $this->stats->catalogItemsDelivered();
    }

    public function getQueuedToday(): int
    {
        return $this->stats->queuedToday();
    }

    /**
     * The latest queue rows, each with the label and pill variant the Log
     * gives its status (PRO-3642): the Log's status filter options name it,
     * StatusPill colours it.
     *
     * @return array<int, array{source: string, type: string, entity_id: string,
     *     status: string, updated_at: string, status_label: string|\Magento\Framework\Phrase,
     *     status_pill: string}>
     */
    public function getRecentActivity(int $limit = 10): array
    {
        $labels = array_column($this->statusOptions->toOptionArray(), 'label', 'value');
        $rows = [];
        foreach ($this->stats->recentActivity($limit) as $row) {
            $row['status_label'] = $labels[$row['status']] ?? $row['status'];
            $row['status_pill'] = $this->statusPill->variant($row['status']);
            $rows[] = $row;
        }

        return $rows;
    }
}
