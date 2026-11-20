<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\ViewModel\Adminhtml;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
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
 * none otherwise — and the 30-day Dismiss. No page load asks the engine.
 */
class IntelligenceBannerDataTest extends TestCase
{
    private const NOW = 1_763_640_000;

    private bool $engineConnected = false;
    private ?string $lastErrorCode = null;
    private bool $verified = true;
    private bool $allowed = true;
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

    public function testNoBannerOnceConnectedOrWithoutAWorkingAccountOrTheResource(): void
    {
        $this->engineConnected = true;
        self::assertSame('', $this->banner()->getVariant());

        $this->engineConnected = false;
        $this->verified = false;
        self::assertSame('', $this->banner()->getVariant());
        self::assertFalse($this->banner()->offersStart());

        $this->verified = true;
        $this->allowed = false;
        self::assertSame('', $this->banner()->getVariant());
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

    private function banner(string $scheme = 'https'): IntelligenceBannerData
    {
        $engine = $this->createMock(EngineSettings::class);
        $engine->method('isConnected')->willReturn($this->engineConnected);
        $reportBuilder = $this->createMock(ReportBuilder::class);
        $reportBuilder->method('mainCredentials')->willReturn($this->main);
        $health = $this->createMock(ConnectionHealth::class);
        $health->method('smaily')->with('shop')->willReturn(
            ['status' => 'failing', 'last_sync_at' => null, 'last_error_code' => $this->lastErrorCode]
        );
        $verified = $this->createMock(VerifiedCredentials::class);
        $verified->method('isWebsiteVerified')->with(2)->willReturn($this->verified);
        $websiteContext = $this->createMock(WebsiteContext::class);
        $websiteContext->method('getWebsiteId')->willReturn(2);
        $start = $this->createMock(IntelligenceStart::class);
        $start->method('isOffered')->willReturn($this->offered);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->with(IntelligenceBannerData::XML_PATH_DISMISSED_AT)
            ->willReturn($this->dismissedAt);
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->with('Smaily_Connect::config')->willReturn($this->allowed);
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
            $authorization,
            $request
        );
    }
}
