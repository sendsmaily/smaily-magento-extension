<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Logger;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Logger\EmailMask;

/**
 * An address is masked however it is written: plainly, URL-encoded or
 * JSON-escaped — the forms in which an error text quotes a URL or a body.
 */
class EmailMaskTest extends TestCase
{
    /**
     * @dataProvider addressProvider
     */
    public function testMasksTheAddressInEveryForm(string $text, string $expected, string $address): void
    {
        $masked = EmailMask::apply($text);

        self::assertSame($expected, $masked);
        self::assertStringNotContainsString($address, $masked);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function addressProvider(): array
    {
        return [
            'plain' => [
                'Contact john@shop.example.org was refused',
                'Contact j***@s***.org was refused',
                'john',
            ],
            'URL-encoded' => [
                'Unknown contact: jane.doe%40example.com (HTTP 404)',
                'Unknown contact: j***%40e***.com (HTTP 404)',
                'jane.doe',
            ],
            'URL-encoded, upper-case local part with an encoded plus' => [
                'email=Jane%2Btag%40Example.COM',
                'e***%40E***.COM',
                'Jane%2Btag',
            ],
            'double URL-encoded' => [
                'next=jane%2540example.com',
                'n***%2540e***.com',
                'jane',
            ],
            'JSON-escaped' => [
                '{"message":"Unknown contact jane\u0040example.com"}',
                '{"message":"Unknown contact j***\u0040e***.com"}',
                'jane',
            ],
            'JSON-escaped twice' => [
                '"{\"email\":\"jane\\\\u0040example.com\"}"',
                '"{\"email\":\"j***\\\\u0040e***.com\"}"',
                'jane',
            ],
        ];
    }

    public function testTextWithoutAnAddressIsUnchanged(): void
    {
        $text = 'HTTP 500 at https://demo.sendsmaily.net/api/contact.php?x=%40home (50% done)';

        self::assertSame($text, EmailMask::apply($text));
    }
}
