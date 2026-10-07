<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Multilingual;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Multilingual\LanguageResolver;

class LanguageResolverTest extends TestCase
{
    /**
     * @dataProvider localeProvider
     */
    public function testForStore(string $locale, string $expected): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($locale);

        self::assertSame($expected, (new LanguageResolver($scopeConfig))->forStore(1));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function localeProvider(): array
    {
        return [
            'estonian' => ['et_EE', 'et'],
            'english us' => ['en_US', 'en'],
            'uppercase input' => ['EN_GB', 'en'],
            'three-letter' => ['fil_PH', 'fil'],
            'empty' => ['', ''],
            'garbage' => ['x', ''],
        ];
    }
}
