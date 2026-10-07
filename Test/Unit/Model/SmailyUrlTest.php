<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\SmailyUrl;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * PRO-3575: only a plain subdomain label becomes the host of a Smaily
 * request.
 */
class SmailyUrlTest extends TestCase
{
    /**
     * @dataProvider plainProvider
     */
    public function testAPlainSubdomainIsAccepted(string $subdomain): void
    {
        self::assertTrue(SmailyUrl::isPlainSubdomain($subdomain));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function plainProvider(): array
    {
        return [
            'letters' => ['demo'],
            'with a hyphen' => ['my-store'],
            'with digits' => ['shop24'],
            'uppercase, as DNS ignores case' => ['Demo'],
            'one character' => ['a'],
            '63 characters' => [str_repeat('a', 63)],
        ];
    }

    /**
     * @dataProvider notPlainProvider
     */
    public function testASubdomainThatWouldChangeTheHostIsRefused(string $subdomain): void
    {
        self::assertFalse(SmailyUrl::isPlainSubdomain($subdomain));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notPlainProvider(): array
    {
        return [
            'empty' => [''],
            'a fragment' => ['engine.example#'],
            'a query' => ['engine.example?'],
            'a path' => ['engine.example/'],
            'a port' => ['demo:8443'],
            'user info' => ['user@engine'],
            'a dot' => ['demo.engine'],
            'an underscore' => ['my_store'],
            'a leading hyphen' => ['-demo'],
            'a trailing hyphen' => ['demo-'],
            'a space' => ['my store'],
            '64 characters' => [str_repeat('a', 64)],
        ];
    }

    /**
     * A full Smaily URL pasted into the field still normalises to a plain
     * subdomain.
     */
    public function testAPastedSmailyUrlNormalisesToAPlainSubdomain(): void
    {
        $subdomain = (new SubdomainNormalizer())->normalize('https://my-store.sendsmaily.net/');

        self::assertSame('my-store', $subdomain);
        self::assertTrue(SmailyUrl::isPlainSubdomain($subdomain));
    }
}
