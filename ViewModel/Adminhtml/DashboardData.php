<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Framework\FlagManager;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Cron\HealthCheck;
use Smaily\Connect\Model\Adminhtml\DashboardStats;
use Smaily\Connect\Model\Adminhtml\SetupGuard;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Health\QueueHealth;

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

    private ?int $failed24h = null;
    private ?bool $smailyConnected = null;
    private ?bool $engineRefused = null;
    private ?bool $engineDown = null;

    public function __construct(
        private readonly Config $config,
        private readonly EngineSettings $engineSettings,
        private readonly SetupGuard $setupGuard,
        private readonly QueueHealth $queueHealth,
        private readonly DashboardStats $stats,
        private readonly FlagManager $flagManager,
        private readonly VerifiedCredentials $verifiedCredentials,
        private readonly WebsiteContext $websiteContext
    ) {
    }

    public function isSetupCompleted(): bool
    {
        return $this->setupGuard->isSetupCompleted();
    }

    /**
     * Whether Smaily accepted the saved credentials at the last real check
     * (PRO-3560) — read at the same scope as the Connection status, the
     * target website's default store view, so the two cannot disagree.
     */
    public function isSmailyConnected(): bool
    {
        return $this->smailyConnected ??= $this->verifiedCredentials->isVerified(
            $this->websiteContext->getStoreId()
        );
    }

    public function getSmailySubdomain(): string
    {
        return $this->config->getSubdomain($this->websiteContext->getStoreId());
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
        if ($this->isEngineRefused() || $this->getFailedLast24h() > 0 || $this->isEngineDown()) {
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
     * @return array<int, array{source: string, type: string, entity_id: string,
     *     status: string, updated_at: string}>
     */
    public function getRecentActivity(int $limit = 10): array
    {
        return $this->stats->recentActivity($limit);
    }
}
