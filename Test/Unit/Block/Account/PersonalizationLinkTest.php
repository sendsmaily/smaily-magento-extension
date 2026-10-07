<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Block\Account;

use Magento\Framework\App\DefaultPathInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Block\Account\PersonalizationLink;
use Smaily\Connect\Model\Engine\Settings;

/**
 * PRO-3579: the My Account "Personalization" link appears only where
 * Campaign Intelligence is live.
 */
class PersonalizationLinkTest extends TestCase
{
    public function testNoLinkWithoutCampaignIntelligence(): void
    {
        self::assertSame('', $this->render(false));
    }

    public function testTheLinkShowsWhereCampaignIntelligenceIsLive(): void
    {
        self::assertStringContainsString('Personalization', $this->render(true));
    }

    private function render(bool $live): string
    {
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(static fn ($value) => (string)$value);
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturn('https://shop.example.com/smaily/privacy/');

        $context = $this->createMock(Context::class);
        $context->method('getEscaper')->willReturn($escaper);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);
        $context->method('getRequest')->willReturn($this->createMock(HttpRequest::class));

        $settings = $this->createMock(Settings::class);
        $settings->method('isSendingAllowed')->willReturn($live);

        $link = new PersonalizationLink(
            $context,
            $this->createMock(DefaultPathInterface::class),
            $settings,
            ['path' => 'smaily/privacy', 'label' => 'Personalization']
        );

        $render = new \ReflectionMethod($link, '_toHtml');

        return (string)$render->invoke($link);
    }
}
