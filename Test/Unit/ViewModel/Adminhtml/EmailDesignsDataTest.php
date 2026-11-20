<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\ViewModel\Adminhtml;

use Magento\Framework\App\Request\Http as HttpRequest;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\EmailDesigns;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\ViewModel\Adminhtml\EmailDesignsData;

/**
 * CON-257, CON-366: an https admin opens the design page in a frame — on
 * the origin of `plugin_info.site_url` (contract §22 Embedding rule 1 (a))
 * or on an origin of its own, which the link names as `frame_origin` (rule
 * 1 (d)); an http admin opens it in a new tab.
 */
class EmailDesignsDataTest extends TestCase
{
    /**
     * @dataProvider originProvider
     */
    public function testTheDesignPageIsFramedOnAnHttpsAdmin(string $scheme, string $host, bool $frameable): void
    {
        self::assertSame($frameable, $this->viewModel($scheme, $host)->isFrameable());
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function originProvider(): array
    {
        return [
            'the storefront host' => ['https', 'shop.example', true],
            'an admin host of its own' => ['https', 'admin.shop.example', true],
            'another port' => ['https', 'shop.example:8443', true],
            'plain http' => ['http', 'shop.example', false],
        ];
    }

    public function testTheEngineOriginComesFromItsBaseUrl(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('getEngineBaseUrl')->willReturn('https://intelligence.smaily.com/');

        $viewModel = new EmailDesignsData(
            $this->createMock(EmailDesigns::class),
            $settings,
            $this->createMock(HttpRequest::class)
        );

        self::assertSame('https://intelligence.smaily.com', $viewModel->getEngineOrigin());
    }

    private function viewModel(string $scheme, string $host): EmailDesignsData
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getScheme')->willReturn($scheme);
        $request->method('getHttpHost')->with(false)->willReturn($host);

        return new EmailDesignsData(
            $this->createMock(EmailDesigns::class),
            $this->createMock(Settings::class),
            $request
        );
    }
}
