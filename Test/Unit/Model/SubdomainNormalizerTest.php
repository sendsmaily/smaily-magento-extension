<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\SubdomainNormalizer;

class SubdomainNormalizerTest extends TestCase
{
    /**
     * @dataProvider inputProvider
     */
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, (new SubdomainNormalizer())->normalize($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function inputProvider(): array
    {
        return [
            'bare subdomain' => ['demo', 'demo'],
            'uppercase' => ['Demo', 'demo'],
            'full https url' => ['https://demo.sendsmaily.net', 'demo'],
            'url with path' => ['https://demo.sendsmaily.net/admin/dashboard', 'demo'],
            'hostname only' => ['demo.sendsmaily.net', 'demo'],
            'with whitespace' => ['  demo  ', 'demo'],
            'trailing dot' => ['demo.', 'demo'],
            'empty' => ['', ''],
            'http url' => ['http://my-store.sendsmaily.net/', 'my-store'],
        ];
    }
}
