<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\StorefrontUrl;

/**
 * PRO-3660: the storefront address accepts an https host alone, and a
 * product link takes it in place of its own scheme, host and port.
 */
class StorefrontUrlTest extends TestCase
{
    /**
     * @dataProvider acceptedProvider
     */
    public function testAnHttpsHostIsAcceptedAndNormalized(string $value, string $expected): void
    {
        self::assertSame($expected, StorefrontUrl::normalize($value));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function acceptedProvider(): array
    {
        return [
            'empty' => ['', ''],
            'blank' => ['   ', ''],
            'host' => ['https://shop.example.com', 'https://shop.example.com'],
            'trailing slash' => ['https://shop.example.com/', 'https://shop.example.com'],
            'surrounding space' => [' https://shop.example.com/ ', 'https://shop.example.com'],
            'upper case' => ['HTTPS://Shop.Example.com', 'https://shop.example.com'],
            'port' => ['https://shop.example.com:8443/', 'https://shop.example.com:8443'],
        ];
    }

    /**
     * @dataProvider refusedProvider
     */
    public function testAnythingButAnHttpsHostIsRefused(string $value): void
    {
        self::assertNull(StorefrontUrl::normalize($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedProvider(): array
    {
        return [
            'http' => ['http://shop.example.com'],
            'no scheme' => ['shop.example.com'],
            'other scheme' => ['ftp://shop.example.com'],
            'path' => ['https://shop.example.com/p'],
            'path with slash' => ['https://shop.example.com/en/'],
            'query' => ['https://shop.example.com/?a=1'],
            'query without path' => ['https://shop.example.com?a=1'],
            'empty query' => ['https://shop.example.com/?'],
            'fragment' => ['https://shop.example.com/#top'],
            'empty fragment' => ['https://shop.example.com#'],
            'user' => ['https://user:secret@shop.example.com'],
            'no host' => ['https://'],
            'space in host' => ['https://shop example.com'],
        ];
    }

    /**
     * @dataProvider rewriteProvider
     */
    public function testAProductLinkTakesTheStorefrontAddressAndKeepsItsPathAndQuery(
        string $saved,
        string $link,
        string $expected
    ): void {
        self::assertSame($expected, $this->storefrontUrl($saved)->apply($link, 1));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function rewriteProvider(): array
    {
        return [
            'host replaced' => [
                'https://shop.example.com',
                'https://backend.example.com/oak-table.html',
                'https://shop.example.com/oak-table.html',
            ],
            'scheme and port replaced, query kept' => [
                'https://shop.example.com',
                'http://backend.example.com:8080/oak-table.html?___store=en&a=1',
                'https://shop.example.com/oak-table.html?___store=en&a=1',
            ],
            'storefront port' => [
                'https://shop.example.com:8443',
                'https://backend.example.com/oak-table.html',
                'https://shop.example.com:8443/oak-table.html',
            ],
            'sub-folder path kept' => [
                'https://shop.example.com',
                'https://backend.example.com/magento/index.php/oak-table.html',
                'https://shop.example.com/magento/index.php/oak-table.html',
            ],
            'link without path' => [
                'https://shop.example.com',
                'https://backend.example.com?x=1',
                'https://shop.example.com?x=1',
            ],
            'empty setting keeps the link' => [
                '',
                'https://backend.example.com/oak-table.html?x=1',
                'https://backend.example.com/oak-table.html?x=1',
            ],
            'invalid stored value keeps the link' => [
                'https://shop.example.com/p',
                'https://backend.example.com/oak-table.html',
                'https://backend.example.com/oak-table.html',
            ],
            'relative link kept' => [
                'https://shop.example.com',
                '/oak-table.html',
                '/oak-table.html',
            ],
        ];
    }

    public function testTheStoresOwnValueIsRead(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects(self::once())->method('getStorefrontUrl')->with(7)->willReturn('https://shop.example.com/');

        self::assertSame(
            'https://shop.example.com/oak.html',
            (new StorefrontUrl($config))->apply('https://backend.example.com/oak.html', 7)
        );
    }

    private function storefrontUrl(string $saved): StorefrontUrl
    {
        $config = $this->createMock(Config::class);
        $config->method('getStorefrontUrl')->willReturn($saved);

        return new StorefrontUrl($config);
    }
}
