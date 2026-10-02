<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\ViewModel\Adminhtml;

use Magento\Framework\FlagManager;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Adminhtml\DashboardStats;
use Smaily\Connect\Model\Adminhtml\SetupGuard;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Health\QueueHealth;
use Smaily\Connect\ViewModel\Adminhtml\DashboardData;

/**
 * A deactivated Campaign Intelligence account is a health state of its own
 * (PRO-2451): the dashboard must name it rather than report an outage.
 * A Smaily connection that Smaily has not accepted is one too (PRO-3560):
 * the verdict and the Smaily card must tell the same story.
 */
class DashboardDataTest extends TestCase
{
    public function testARefusedAccountIsReportedAndDegradesTheVerdict(): void
    {
        $viewModel = $this->createViewModel(true);

        self::assertTrue($viewModel->isEngineRefused());
        self::assertSame(DashboardData::VERDICT_DEGRADED, $viewModel->getVerdict());
    }

    public function testAHealthyInstallIsUnaffected(): void
    {
        $viewModel = $this->createViewModel(false);

        self::assertFalse($viewModel->isEngineRefused());
        self::assertTrue($viewModel->isSmailyConnected());
        self::assertSame(DashboardData::VERDICT_OK, $viewModel->getVerdict());
    }

    public function testAnUnacceptedSmailyConnectionIsTheVerdict(): void
    {
        $viewModel = $this->createViewModel(false, smailyVerified: false);

        self::assertFalse($viewModel->isSmailyConnected());
        self::assertSame(DashboardData::VERDICT_DISCONNECTED, $viewModel->getVerdict());
    }

    public function testAnUnacceptedSmailyConnectionOutranksFailuresAndARefusedEngine(): void
    {
        $viewModel = $this->createViewModel(true, smailyVerified: false, failed: 3);

        self::assertSame(DashboardData::VERDICT_DISCONNECTED, $viewModel->getVerdict());
    }

    /**
     * PRO-3579: a package without API access is "Not connected" too —
     * nothing reaches Smaily — but the Dashboard can tell it apart from
     * refused credentials.
     */
    public function testAPackageWithoutApiAccessIsNotConnectedAndNamed(): void
    {
        $viewModel = $this->createViewModel(false, smailyVerified: false, planBlocked: true);

        self::assertFalse($viewModel->isSmailyConnected());
        self::assertTrue($viewModel->isSmailyPlanBlocked());
        self::assertSame(DashboardData::VERDICT_DISCONNECTED, $viewModel->getVerdict());
    }

    public function testRefusedCredentialsAreNotABlockedPackage(): void
    {
        $viewModel = $this->createViewModel(false, smailyVerified: false);

        self::assertFalse($viewModel->isSmailyPlanBlocked());
    }

    public function testAnUnfinishedSetupOutranksTheConnection(): void
    {
        $viewModel = $this->createViewModel(false, smailyVerified: false, setupCompleted: false);

        self::assertSame(DashboardData::VERDICT_INCOMPLETE, $viewModel->getVerdict());
    }

    private function createViewModel(
        bool $refused,
        bool $smailyVerified = true,
        int $failed = 0,
        bool $setupCompleted = true,
        bool $planBlocked = false
    ): DashboardData {
        $engineSettings = $this->createMock(EngineSettings::class);
        $engineSettings->method('isConnected')->willReturn(true);
        $engineSettings->method('isRefused')->willReturn($refused);

        $setupGuard = $this->createMock(SetupGuard::class);
        $setupGuard->method('isSetupCompleted')->willReturn($setupCompleted);

        $queueHealth = $this->createMock(QueueHealth::class);
        $queueHealth->method('failedSince')->willReturn($failed);

        // The card and the verdict judge the same scope as the Connection
        // status: the target website's default store view.
        $websiteContext = $this->createMock(WebsiteContext::class);
        $websiteContext->method('getStoreId')->willReturn(4);
        $verifiedCredentials = $this->createMock(VerifiedCredentials::class);
        $verifiedCredentials->method('isVerified')->with(4)->willReturn($smailyVerified);
        $verifiedCredentials->method('isPlanBlocked')->with(4)->willReturn($planBlocked);

        return new DashboardData(
            $this->createMock(Config::class),
            $engineSettings,
            $setupGuard,
            $queueHealth,
            $this->createMock(DashboardStats::class),
            $this->createMock(FlagManager::class),
            $verifiedCredentials,
            $websiteContext
        );
    }
}
