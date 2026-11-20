<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\ViewModel\Adminhtml;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Report\Credentials;
use Smaily\Connect\Model\Report\ReportBuilder;
use Smaily\Connect\ViewModel\Adminhtml\SmailyInvitationData;

/**
 * CON-364: the invitation to connect a Smaily account (as the WooCommerce
 * plugin's CON-321 / CON-351) is due while no Smaily marketing account is
 * connected (ReportBuilder::mainCredentials(); a Transactional account alone
 * is ReportBuilderTest's case), only for an admin who may open the
 * Settings, and its Dismiss hides it for 90 days. Its links: Settings >
 * Connection, Smaily sign-up, the guide's Step 1, and Dismiss with the form
 * key.
 */
class SmailyInvitationDataTest extends TestCase
{
    private const NOW = 1_763_640_000;

    private ?Credentials $main = null;
    private bool $allowed = true;
    private string $dismissedAt = '';

    /** @var array<int, array{string, array<string, mixed>}> */
    private array $urls = [];

    public function testDueWhileNoSmailyMarketingAccountIsConnected(): void
    {
        self::assertTrue($this->invitation()->isDue());

        $this->main = new Credentials('shop', 'api-user', 'not-a-real-password');
        self::assertFalse($this->invitation()->isDue());
    }

    public function testNotDueForAnAdminWhoCannotOpenTheSettings(): void
    {
        $this->allowed = false;

        self::assertFalse($this->invitation()->isDue());
    }

    public function testDismissHidesItForNinetyDays(): void
    {
        self::assertFalse($this->invitation()->isDismissed());

        $this->dismissedAt = (string)(self::NOW - 89 * 86400);
        self::assertTrue($this->invitation()->isDismissed());

        $this->dismissedAt = (string)(self::NOW - 90 * 86400);
        self::assertFalse($this->invitation()->isDismissed());
    }

    public function testItsLinks(): void
    {
        $invitation = $this->invitation();

        self::assertSame('https://www.sendsmaily.net/account/register/', $invitation->getSignupUrl());
        self::assertSame('https://smaily.com/connect-magento/#setup-connect', $invitation->getGuideUrl());
        self::assertSame('admin/smaily_connect/settings', $invitation->getConnectUrl());
        self::assertSame('admin/smaily_connect/invitation/dismiss', $invitation->getDismissUrl());
        self::assertSame([
            ['smaily_connect/settings', ['_query' => ['tab' => 'connection']]],
            ['smaily_connect/invitation/dismiss', ['_query' => ['form_key' => 'form-key']]],
        ], $this->urls);
    }

    private function invitation(): SmailyInvitationData
    {
        $reportBuilder = $this->createMock(ReportBuilder::class);
        $reportBuilder->method('mainCredentials')->willReturn($this->main);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->with(SmailyInvitationData::XML_PATH_DISMISSED_AT)
            ->willReturn($this->dismissedAt);
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->with('Smaily_Connect::config')->willReturn($this->allowed);
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(function (string $route, array $params): string {
            $this->urls[] = [$route, $params];

            return 'admin/' . $route;
        });
        $formKey = $this->createMock(FormKey::class);
        $formKey->method('getFormKey')->willReturn('form-key');

        return new SmailyInvitationData($reportBuilder, $scopeConfig, $dateTime, $authorization, $urlBuilder, $formKey);
    }
}
