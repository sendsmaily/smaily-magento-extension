<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\RateLimit;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\RateLimit\FixedWindowCounter;
use Smaily\Connect\Model\RateLimit\PerAddressLimiter;

/**
 * The per-address limit of the anonymous storefront endpoints: the cache key
 * each caller counts on, the window, the key's lifetime and the limit.
 */
class PerAddressLimiterTest extends TestCase
{
    private const NOW = 1_790_000_000;

    /**
     * @var array<string, array{value: string, ttl: int|null}>
     */
    private array $cacheStore = [];

    private string $ip = '203.0.113.7';

    public function testKeysTheCallerByPrefixAddressHashAndMinute(): void
    {
        self::assertTrue($this->limiter()->allow('smaily_relay_', 30));

        $key = 'smaily_relay_' . sha1('203.0.113.7') . '_' . intdiv(self::NOW, 60);
        self::assertSame([$key => ['value' => '1', 'ttl' => 120]], $this->cacheStore);
    }

    public function testRefusesTheRequestPastTheLimitWithoutCountingIt(): void
    {
        $limiter = $this->limiter();

        self::assertTrue($limiter->allow('smaily_recs_', 2));
        self::assertTrue($limiter->allow('smaily_recs_', 2));
        self::assertFalse($limiter->allow('smaily_recs_', 2));
        self::assertSame(['2'], array_column($this->cacheStore, 'value'));
    }

    public function testAnotherAddressCountsOnItsOwnKey(): void
    {
        $limiter = $this->limiter();

        self::assertTrue($limiter->allow('smaily_recs_', 1));
        $this->ip = '198.51.100.9';
        self::assertTrue($limiter->allow('smaily_recs_', 1));
        self::assertCount(2, $this->cacheStore);
    }

    public function testALongerWindowKeysByThatWindowAndLivesForTwo(): void
    {
        self::assertTrue($this->limiter()->allow('smaily_cart_email_', 30, 600, true));

        $key = 'smaily_cart_email_' . sha1('203.0.113.7') . '_' . intdiv(self::NOW, 600);
        self::assertSame([$key => ['value' => '1', 'ttl' => 1200]], $this->cacheStore);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function callers(): array
    {
        return [
            'IPv6 counts by its /64' => ['2001:db8:1:2:aaaa::1', '20010db800010002/64'],
            'IPv4-mapped IPv6 counts as its IPv4' => ['::ffff:203.0.113.7', '203.0.113.7'],
            'IPv4 as it is' => ['203.0.113.7', '203.0.113.7'],
        ];
    }

    /**
     * @dataProvider callers
     */
    public function testWithIpv6By64AnIpv6CallerCountsByItsSubnet(string $ip, string $caller): void
    {
        $this->ip = $ip;

        self::assertTrue($this->limiter()->allow('smaily_cart_email_', 30, 600, true));
        self::assertSame(
            ['smaily_cart_email_' . sha1($caller) . '_' . intdiv(self::NOW, 600)],
            array_keys($this->cacheStore)
        );
    }

    public function testWithoutIpv6By64AnIpv6CallerCountsByItsWholeAddress(): void
    {
        $this->ip = '2001:db8:1:2:aaaa::1';

        self::assertTrue($this->limiter()->allow('smaily_relay_', 30));
        self::assertSame(
            ['smaily_relay_' . sha1('2001:db8:1:2:aaaa::1') . '_' . intdiv(self::NOW, 60)],
            array_keys($this->cacheStore)
        );
    }

    private function limiter(): PerAddressLimiter
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            fn (string $key): string|false => $this->cacheStore[$key]['value'] ?? false
        );
        $cache->method('save')->willReturnCallback(
            function (string $value, string $key, array $tags = [], ?int $ttl = null): bool {
                $this->cacheStore[$key] = ['value' => $value, 'ttl' => $ttl];

                return true;
            }
        );
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);
        $remoteAddress = $this->createMock(RemoteAddress::class);
        $remoteAddress->method('getRemoteAddress')->willReturnCallback(fn (): string => $this->ip);

        return new PerAddressLimiter(new FixedWindowCounter($cache), $dateTime, $remoteAddress);
    }
}
