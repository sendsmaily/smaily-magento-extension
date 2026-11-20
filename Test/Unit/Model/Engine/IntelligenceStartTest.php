<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\IntelligenceStart;
use Smaily\Connect\Model\Engine\Onboarding;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\RateLimit\FixedWindowCounter;
use Smaily\Connect\Model\Report\Credentials;
use Smaily\Connect\Model\Report\ReportBuilder;
use Smaily\Connect\Model\StoreLayout;

/**
 * CON-363: "Try Intelligence free" (contract §21, as the WooCommerce
 * plugin's CON-175). The button is offered only to a shop with one store,
 * one Smaily account and no engine connection, and shows only while the
 * engine says the start is open — that answer is kept five minutes, and any
 * doubt is "closed". The start sends the main account's login, the admin's
 * email and language and the frame request, and answers a refusal by code
 * only.
 */
class IntelligenceStartTest extends TestCase
{
    private const SHOP = 'https://shop.example';
    private const START_URL = 'https://intelligence.smaily.com/onboarding/start/f/AQx#t=AQ3f';

    /** @var array<string, string> the application cache */
    private array $cache = [];

    /** @var array<string, int> the TTL each cache key was saved with */
    private array $ttls = [];

    private bool $connected = false;
    private bool $separateStores = false;
    private bool $oneAccount = true;
    private ?Credentials $main = null;
    private string $siteUrl = self::SHOP;
    private bool $engineOpen = true;
    private int $statusCalls = 0;

    /** @var array<int, array<string, string>> */
    private array $starts = [];

    /** @var array{status: int, body: array<string, mixed>} */
    private array $startAnswer = ['status' => 200, 'body' => ['start_url' => self::START_URL, 'display' => 'frame']];

    protected function setUp(): void
    {
        $this->main = new Credentials('shop', 'api-user', 'not-a-real-password');
    }

    public function testTheEnginesAnswerIsKeptFiveMinutes(): void
    {
        $start = $this->start();

        self::assertTrue($start->isStartOpen());
        self::assertTrue($start->isStartOpen());
        self::assertSame(1, $this->statusCalls);
        self::assertSame('1', $this->cache[IntelligenceStart::STATUS_CACHE_KEY]);
        self::assertSame(300, $this->ttls[IntelligenceStart::STATUS_CACHE_KEY]);
    }

    public function testAClosedOrFailedAnswerIsKeptAsClosed(): void
    {
        $this->engineOpen = false;
        $start = $this->start();

        self::assertFalse($start->isStartOpen());
        self::assertFalse($start->isStartOpen());
        self::assertSame(1, $this->statusCalls);
        self::assertSame('0', $this->cache[IntelligenceStart::STATUS_CACHE_KEY]);
    }

    /**
     * @dataProvider notOffered
     */
    public function testTheEngineIsNotAskedWhenTheStartIsNotOffered(string $case): void
    {
        match ($case) {
            'connected' => $this->connected = true,
            'separate stores' => $this->separateStores = true,
            'several accounts or per-language accounts' => $this->oneAccount = false,
            default => throw new \LogicException('Unknown case ' . $case),
        };
        $start = $this->start();

        self::assertFalse($start->isOffered());
        self::assertFalse($start->isStartOpen());
        self::assertSame(0, $this->statusCalls);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notOffered(): array
    {
        return [
            'connected' => ['connected'],
            'separate stores' => ['separate stores'],
            'several accounts or per-language accounts' => ['several accounts or per-language accounts'],
        ];
    }

    public function testAFrameStartSendsTheMainAccountTheAdminAndTheLanguage(): void
    {
        $answer = $this->start()->start(true, self::SHOP, 'owner@shop.example', 'et');

        self::assertSame([
            'shop_url' => self::SHOP,
            'platform' => 'magento',
            'smaily_subdomain' => 'shop',
            'smaily_username' => 'api-user',
            'smaily_password' => 'not-a-real-password',
            'language' => 'et',
            'display' => 'frame',
            'admin_email' => 'owner@shop.example',
        ], $this->starts[0], 'No frame_origin: the admin is on the shop\'s own origin.');
        self::assertSame(200, $answer['status']);
        self::assertSame([
            'start_url' => self::START_URL,
            'display' => 'frame',
            'engine_origin' => 'https://intelligence.smaily.com',
        ], $answer['body']);
    }

    public function testAnAdminOnItsOwnHostNamesItsOriginAsTheFrameOrigin(): void
    {
        $this->start()->start(true, 'https://admin.shop.example', '', 'en');

        self::assertSame('frame', $this->starts[0]['display']);
        self::assertSame('https://admin.shop.example', $this->starts[0]['frame_origin']);
        self::assertArrayNotHasKey('admin_email', $this->starts[0]);
    }

    public function testAnAdminOnHttpAsksForANewWindow(): void
    {
        $answer = $this->start()->start(true, 'http://shop.example', '', 'en');

        self::assertArrayNotHasKey('display', $this->starts[0]);
        self::assertArrayNotHasKey('frame_origin', $this->starts[0]);
        self::assertSame('window', $answer['body']['display']);
    }

    public function testAnEngineWithoutTheFrameModeOpensAWindow(): void
    {
        $this->startAnswer = ['status' => 200, 'body' => ['start_url' => self::START_URL]];

        self::assertSame('window', $this->start()->start(true, self::SHOP, '', 'en')['body']['display']);
    }

    public function testAStartLinkOffTheEngineHostIsNotPassedOn(): void
    {
        $this->startAnswer = ['status' => 200, 'body' => ['start_url' => 'https://elsewhere.example/start#t=a']];

        self::assertSame(
            ['status' => 502, 'body' => ['error' => 'start_failed']],
            $this->start()->start(false, self::SHOP, '', 'en')
        );
    }

    /**
     * @dataProvider refusals
     * @param array<string, mixed> $engineBody
     * @param array<string, string> $expected
     */
    public function testARefusalIsAnsweredByItsCodeOnly(int $status, array $engineBody, int $expectedStatus, array $expected): void
    {
        $this->startAnswer = ['status' => $status, 'body' => $engineBody + ['message' => 'The engine\'s own words']];

        $answer = $this->start()->start(true, self::SHOP, '', 'en');

        self::assertSame(['status' => $expectedStatus, 'body' => $expected], $answer);
    }

    /**
     * @return array<string, array{int, array<string, mixed>, int, array<string, string>}>
     */
    public static function refusals(): array
    {
        $console = 'https://intelligence.smaily.com/console/6d1f0c3e/home';

        return [
            'frame origin' => [400, ['error' => 'frame_origin_not_allowed'], 400, ['error' => 'frame_origin_not_allowed']],
            'another 400' => [400, ['error' => 'validation_failed'], 502, ['error' => 'start_failed']],
            'login refused' => [401, ['error' => 'smaily_login_refused'], 401, ['error' => 'smaily_login_refused']],
            'paid package' => [403, ['error' => 'smaily_paid_package_required'], 403, ['error' => 'smaily_paid_package_required']],
            'store exists' => [409, ['error' => 'store_exists', 'console_url' => $console], 409, ['error' => 'store_exists', 'console_url' => $console]],
            'store exists, console elsewhere' => [409, ['error' => 'store_exists', 'console_url' => 'https://elsewhere.example/console'], 409, ['error' => 'store_exists']],
            'rate limit' => [429, ['error' => 'rate_limit_exceeded'], 429, ['error' => 'rate_limited']],
            'smaily unavailable' => [502, ['error' => 'smaily_unavailable'], 502, ['error' => 'unavailable']],
            'no answer' => [0, [], 502, ['error' => 'unavailable']],
            'redirect' => [302, [], 502, ['error' => 'start_failed']],
        ];
    }

    public function testA404ClosesTheStartForFiveMinutes(): void
    {
        $this->cache[IntelligenceStart::STATUS_CACHE_KEY] = '1';
        $this->startAnswer = ['status' => 404, 'body' => ['error' => 'not_found']];
        $start = $this->start();

        self::assertSame(['status' => 404, 'body' => ['error' => 'not_found']], $start->start(false, self::SHOP, '', 'en'));
        self::assertSame('0', $this->cache[IntelligenceStart::STATUS_CACHE_KEY]);
        self::assertFalse($start->isStartOpen());
    }

    public function testNothingIsSentWhenTheStartDoesNotApply(): void
    {
        $this->connected = true;
        self::assertSame(['status' => 409, 'body' => ['error' => 'already_connected']], $this->start()->start(false, self::SHOP, '', 'en'));

        $this->connected = false;
        $this->main = null;
        self::assertSame(['status' => 401, 'body' => ['error' => 'smaily_login_refused']], $this->start()->start(false, self::SHOP, '', 'en'));

        $this->main = new Credentials('shop', 'api-user', 'not-a-real-password');
        $this->separateStores = true;
        self::assertSame(['status' => 404, 'body' => ['error' => 'not_found']], $this->start()->start(false, self::SHOP, '', 'en'));

        $this->separateStores = false;
        $this->siteUrl = '';
        self::assertSame(['status' => 502, 'body' => ['error' => 'start_failed']], $this->start()->start(false, self::SHOP, '', 'en'));

        self::assertSame([], $this->starts);
    }

    public function testTenStartsAMinuteLeaveTheInstallation(): void
    {
        $start = $this->start();
        for ($i = 0; $i < 10; $i++) {
            self::assertSame(200, $start->start(false, self::SHOP, '', 'en')['status']);
        }

        self::assertSame(['status' => 429, 'body' => ['error' => 'rate_limited']], $start->start(false, self::SHOP, '', 'en'));
        self::assertCount(10, $this->starts);
    }

    public function testTheLanguageIsEstonianOnlyForAnEstonianAdmin(): void
    {
        self::assertSame('et', IntelligenceStart::language('et_EE'));
        self::assertSame('en', IntelligenceStart::language('en_US'));
        self::assertSame('en', IntelligenceStart::language('fi_FI'));
    }

    private function start(): IntelligenceStart
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturnCallback(fn (): bool => $this->connected);
        $reportBuilder = $this->createMock(ReportBuilder::class);
        $reportBuilder->method('mainCredentials')->willReturnCallback(fn (): ?Credentials => $this->main);
        $reportBuilder->method('hasOneSmailyAccount')->willReturnCallback(
            fn (): bool => $this->oneAccount && $this->main !== null
        );
        $storeLayout = $this->createMock(StoreLayout::class);
        $storeLayout->method('isSeparateStores')->willReturnCallback(fn (): bool => $this->separateStores);
        $onboarding = $this->createMock(Onboarding::class);
        $onboarding->method('isStartOpen')->willReturnCallback(function (): bool {
            $this->statusCalls++;

            return $this->engineOpen;
        });
        $onboarding->method('start')->willReturnCallback(function (array $body): array {
            $this->starts[] = $body;

            return $this->startAnswer;
        });
        $client = $this->createMock(Client::class);
        $client->method('siteUrl')->willReturnCallback(fn (): string => $this->siteUrl);
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_763_640_000);

        return new IntelligenceStart(
            $settings,
            $reportBuilder,
            $storeLayout,
            $onboarding,
            $client,
            $this->cache(),
            new FixedWindowCounter($this->cache()),
            $dateTime
        );
    }

    private function cache(): CacheInterface&MockObject
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key): string|false => $this->cache[$key] ?? false);
        $cache->method('save')->willReturnCallback(
            function (string $data, string $key, array $tags = [], ?int $ttl = null): bool {
                $this->cache[$key] = $data;
                $this->ttls[$key] = (int)$ttl;

                return true;
            }
        );

        return $cache;
    }
}
