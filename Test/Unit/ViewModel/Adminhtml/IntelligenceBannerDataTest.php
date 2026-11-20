<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\ViewModel\Adminhtml;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Adminhtml\Dashboard\Index as DashboardPage;
use Smaily\Connect\Controller\Adminhtml\Settings\Index as SettingsPage;
use Smaily\Connect\Controller\Adminhtml\Wizard\Index as WizardPage;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Engine\IntelligenceStart;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Report\ConnectionHealth;
use Smaily\Connect\Model\Report\Credentials;
use Smaily\Connect\Model\Report\ReportBuilder;
use Smaily\Connect\ViewModel\Adminhtml\IntelligenceBannerData;

/**
 * CON-363: which Campaign Intelligence banner the admin sees (as the
 * WooCommerce plugin's CON-350) — none once Campaign Intelligence is
 * connected or without a Smaily marketing account (CON-364), the upsell
 * after Smaily's code 227, the invitation for an account Smaily accepted,
 * none otherwise — only for admins who may open the Settings, and the
 * 30-day Dismiss. No page load asks the engine, and a page reads the
 * Smaily accounts once.
 */
class IntelligenceBannerDataTest extends TestCase
{
    private const NOW = 1_763_640_000;

    private bool $engineConnected = false;
    private ?string $lastErrorCode = null;
    private bool $verified = true;
    private bool $offered = true;
    private string $dismissedAt = '';
    private ?Credentials $main = null;

    protected function setUp(): void
    {
        $this->main = new Credentials('shop', 'api-user', 'not-a-real-password');
    }

    public function testAnAccountSmailyAcceptedIsInvited(): void
    {
        $banner = $this->banner();

        self::assertSame(IntelligenceBannerData::VARIANT_INVITE, $banner->getVariant());
        self::assertTrue($banner->offersStart());
        self::assertSame('https://smaily.com/consultation/', $banner->getConsultationUrl());
    }

    public function testCode227ShowsTheUpsellWithTheAccountsOwnLogin(): void
    {
        $this->lastErrorCode = ConnectionHealth::PLAN_BLOCKED;
        $this->verified = false;
        $banner = $this->banner();

        self::assertSame(IntelligenceBannerData::VARIANT_UPSELL, $banner->getVariant());
        self::assertFalse($banner->offersStart());
        self::assertSame('https://shop.sendsmaily.net/', $banner->getLoginUrl());
    }

    public function testNoBannerOnceConnectedOrWithoutAWorkingAccount(): void
    {
        $this->engineConnected = true;
        self::assertSame('', $this->banner()->getVariant());

        $this->engineConnected = false;
        $this->verified = false;
        self::assertSame('', $this->banner()->getVariant());
        self::assertFalse($this->banner()->offersStart());
    }

    public function testThePageAsksForTheAccountsOncePerRequest(): void
    {
        $this->lastErrorCode = ConnectionHealth::PLAN_BLOCKED;
        $banner = $this->banner(mainReads: 1, offeredReads: 0);

        // Two banners on one page (Settings) share the view model.
        for ($i = 0; $i < 2; $i++) {
            self::assertSame(IntelligenceBannerData::VARIANT_UPSELL, $banner->getVariant());
            self::assertSame('https://shop.sendsmaily.net/', $banner->getLoginUrl());
            self::assertFalse($banner->offersStart());
        }

        $this->lastErrorCode = null;
        $banner = $this->banner(mainReads: 1, offeredReads: 1);
        for ($i = 0; $i < 2; $i++) {
            self::assertTrue($banner->offersStart());
            self::assertSame('https://shop.sendsmaily.net/', $banner->getLoginUrl());
        }
    }

    /**
     * The Dashboard needs only Smaily_Connect::connect, so its banner and
     * start blocks carry Smaily_Connect::config as their `aclResource`; the
     * setup and Settings pages need that resource themselves. Every admin
     * role sees the banner exactly where it may open the Settings.
     */
    public function testOnlyAdminsWhoMayOpenTheSettingsGetTheBanner(): void
    {
        $root = __DIR__ . '/../../../..';
        $pages = [
            'dashboard' => DashboardPage::ADMIN_RESOURCE,
            'settings' => SettingsPage::ADMIN_RESOURCE,
            'wizard' => WizardPage::ADMIN_RESOURCE,
        ];
        $blocks = 0;
        foreach ($pages as $page => $pageResource) {
            $layout = simplexml_load_file($root . '/view/adminhtml/layout/smaily_connect_' . $page . '_index.xml');
            self::assertNotFalse($layout, $page);
            $banners = $layout->xpath(
                '//block[arguments/argument[@name="view_model"]'
                . '[normalize-space(.)="' . IntelligenceBannerData::class . '"]]'
            );
            self::assertNotEmpty($banners, $page);
            foreach ($banners as $banner) {
                $blocks++;
                $resource = (string)$banner['aclResource'] !== '' ? (string)$banner['aclResource'] : $pageResource;
                self::assertSame('Smaily_Connect::config', $resource, $page . ': ' . $banner['name']);
            }
        }
        self::assertSame(7, $blocks);
        self::assertSame('Smaily_Connect::connect', $pages['dashboard']);
    }

    public function testNoBannerWithoutASmailyMarketingAccount(): void
    {
        // CON-364: the Smaily invitation shows instead.
        $this->main = null;

        self::assertSame('', $this->banner()->getVariant());
        self::assertFalse($this->banner()->offersStart());
    }

    public function testTheStartIsNotOfferedWhereTheShopCannotStartItself(): void
    {
        $this->offered = false;

        self::assertSame(IntelligenceBannerData::VARIANT_INVITE, $this->banner()->getVariant());
        self::assertFalse($this->banner()->offersStart());
    }

    public function testDismissHidesTheBannerForThirtyDays(): void
    {
        self::assertFalse($this->banner()->isDismissed());

        $this->dismissedAt = (string)(self::NOW - 29 * 86400);
        self::assertTrue($this->banner()->isDismissed());

        $this->dismissedAt = (string)(self::NOW - 30 * 86400);
        self::assertFalse($this->banner()->isDismissed());
    }

    public function testTheStartWindowFramesOnlyOnAnHttpsAdmin(): void
    {
        self::assertTrue($this->banner('https')->isFrameable());
        self::assertFalse($this->banner('http')->isFrameable());
    }

    private function banner(
        string $scheme = 'https',
        ?int $mainReads = null,
        ?int $offeredReads = null
    ): IntelligenceBannerData {
        $engine = $this->createMock(EngineSettings::class);
        $engine->method('isConnected')->willReturn($this->engineConnected);
        $reportBuilder = $this->createMock(ReportBuilder::class);
        $reportBuilder->expects($mainReads === null ? self::any() : self::exactly($mainReads))
            ->method('mainCredentials')->willReturn($this->main);
        $health = $this->createMock(ConnectionHealth::class);
        $health->method('smaily')->with('shop')->willReturn(
            ['status' => 'failing', 'last_sync_at' => null, 'last_error_code' => $this->lastErrorCode]
        );
        $verified = $this->createMock(VerifiedCredentials::class);
        $verified->method('isWebsiteVerified')->with(2)->willReturn($this->verified);
        $websiteContext = $this->createMock(WebsiteContext::class);
        $websiteContext->method('getWebsiteId')->willReturn(2);
        $start = $this->createMock(IntelligenceStart::class);
        $start->expects($offeredReads === null ? self::any() : self::exactly($offeredReads))
            ->method('isOffered')->willReturn($this->offered);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->with(IntelligenceBannerData::XML_PATH_DISMISSED_AT)
            ->willReturn($this->dismissedAt);
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);
        $request = $this->createMock(HttpRequest::class);
        $request->method('getScheme')->willReturn($scheme);
        $request->method('getHttpHost')->willReturn('shop.example');

        return new IntelligenceBannerData(
            $engine,
            $reportBuilder,
            $health,
            $verified,
            $websiteContext,
            $start,
            $scopeConfig,
            $dateTime,
            $request
        );
    }
}
