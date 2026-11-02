<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\ViewModel\Adminhtml;

use Magento\Framework\App\Request\Http as HttpRequest;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\EmailDesigns;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\ViewModel\Adminhtml\EmailDesignsData;

/**
 * CON-257: the engine lets only the origin of `plugin_info.site_url`, on
 * https, frame its design page (contract §22 Embedding rule 1); any other
 * admin opens the page in a new tab.
 */
class EmailDesignsDataTest extends TestCase
{
    /**
     * @dataProvider originProvider
     */
    public function testTheDesignPageIsFramedOnlyOnTheSiteOrigin(
        string $siteUrl,
        string $scheme,
        string $host,
        bool $frameable
    ): void {
        self::assertSame($frameable, $this->viewModel($siteUrl, $scheme, $host)->isFrameable());
    }

    /**
     * @return array<string, array{string, string, string, bool}>
     */
    public static function originProvider(): array
    {
        return [
            'the storefront host' => ['https://shop.example/', 'https', 'shop.example', true],
            'a store view path' => ['https://shop.example/et/', 'https', 'shop.example', true],
            'the default port written out' => ['https://shop.example:443/', 'https', 'shop.example', true],
            'an admin host of its own' => ['https://shop.example/', 'https', 'admin.shop.example', false],
            'another port' => ['https://shop.example/', 'https', 'shop.example:8443', false],
            'plain http' => ['http://shop.example/', 'http', 'shop.example', false],
            'no site URL' => ['', 'https', 'shop.example', false],
        ];
    }

    public function testTheEngineOriginComesFromItsBaseUrl(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('getEngineBaseUrl')->willReturn('https://intelligence.smaily.com/');

        $viewModel = new EmailDesignsData(
            $this->createMock(EmailDesigns::class),
            $settings,
            $this->createMock(Client::class),
            $this->createMock(HttpRequest::class)
        );

        self::assertSame('https://intelligence.smaily.com', $viewModel->getEngineOrigin());
    }

    private function viewModel(string $siteUrl, string $scheme, string $host): EmailDesignsData
    {
        $client = $this->createMock(Client::class);
        $client->method('siteUrl')->willReturn($siteUrl);
        $request = $this->createMock(HttpRequest::class);
        $request->method('getScheme')->willReturn($scheme);
        $request->method('getHttpHost')->with(false)->willReturn($host);

        return new EmailDesignsData(
            $this->createMock(EmailDesigns::class),
            $this->createMock(Settings::class),
            $client,
            $request
        );
    }
}
