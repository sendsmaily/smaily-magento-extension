<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\CacheInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\StorefrontRecommendations;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\ProfilingConsent;

/**
 * PRO-3790: who the engine is asked about, and how its answer is cached.
 * Values are synthetic.
 */
class StorefrontRecommendationsTest extends TestCase
{
    private const REC_ID = '3fa85f64-5717-4562-b3fc-2c963f66afa6';

    private Settings&MockObject $settings;

    private Client&MockObject $client;

    private ProfilingConsent&MockObject $profiling;

    private CustomerSession&MockObject $session;

    private CookieHelper&MockObject $cookieHelper;

    /** @var array<string, array{value: string, ttl: int|null}> */
    private array $cacheStore = [];

    private ?string $visitorToken = null;

    /** @var array<int, array{string, string, int}> */
    private array $engineCalls = [];

    /** @var array<string, mixed>|\Throwable */
    private array|\Throwable $engineAnswer = ['slots' => []];

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isSendingAllowed')->willReturn(true);
        $this->settings->method('getTenantId')->willReturn('tenant-1');

        $this->client = $this->createMock(Client::class);
        $this->client->method('recommendations')->willReturnCallback(
            function (array $identifier, int $limit): array {
                $this->engineCalls[] = [(string)array_key_first($identifier), current($identifier), $limit];
                if ($this->engineAnswer instanceof \Throwable) {
                    throw $this->engineAnswer;
                }

                return $this->engineAnswer;
            }
        );

        $this->profiling = $this->createMock(ProfilingConsent::class);
        $this->session = $this->createMock(CustomerSession::class);
        $this->cookieHelper = $this->createMock(CookieHelper::class);
    }

    public function testNothingIsAskedWhileTheEngineMayNotBeCalled(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isSendingAllowed')->willReturn(false);
        $this->guest('vt_8f3k2a');

        self::assertSame([], $this->service($settings)->slots());
        self::assertSame([], $this->engineCalls);
    }

    public function testALoggedInShopperIsAskedAboutByTheCustomerIdOnly(): void
    {
        $this->loggedIn(42, 'shopper@example.com', true);
        $this->visitorToken = 'vt_8f3k2a';
        $this->engineAnswer = ['slots' => [['rec_id' => self::REC_ID, 'external_id' => '7', 'sku' => 'MJ01']]];

        $slots = $this->service()->slots();

        self::assertSame([['customer_external_id', '42', 4]], $this->engineCalls);
        self::assertSame([['rec_id' => self::REC_ID, 'external_id' => '7', 'sku' => 'MJ01']], $slots);
    }

    public function testALoggedInShopperWhoObjectedIsNeverAskedAboutNotEvenByTheToken(): void
    {
        $this->loggedIn(42, 'shopper@example.com', false);
        $this->visitorToken = 'vt_8f3k2a';

        self::assertSame([], $this->service()->slots());
        self::assertSame([], $this->engineCalls);
    }

    public function testAGuestIsAskedAboutByTheVisitorToken(): void
    {
        $this->guest('vt_8f3k2a');

        $this->service()->slots();

        self::assertSame([['smaily_visitor_token', 'vt_8f3k2a', 4]], $this->engineCalls);
    }

    public function testAGuestTheCookieNoticeHoldsBackIsNotAskedAbout(): void
    {
        $this->guest('vt_8f3k2a', false);

        self::assertSame([], $this->service()->slots());
        self::assertSame([], $this->engineCalls);
    }

    public function testAGuestWithoutAVisitorTokenIsNotAskedAbout(): void
    {
        $this->guest(null);

        self::assertSame([], $this->service()->slots());
        self::assertSame([], $this->engineCalls);
    }

    public function testTheAnswerIsCachedForAnHourPerShopper(): void
    {
        $this->guest('vt_8f3k2a');
        $this->engineAnswer = ['slots' => [['rec_id' => self::REC_ID, 'external_id' => '7', 'sku' => 'MJ01']]];

        $first = $this->service()->slots();
        $second = $this->service()->slots();

        self::assertSame($first, $second);
        self::assertCount(1, $this->engineCalls, 'the second call is answered from the cache');
        $entry = $this->cacheEntry();
        self::assertSame(3600, $entry['ttl']);
        self::assertStringNotContainsString('vt_8f3k2a', (string)array_key_first($this->cacheStore));

        $this->visitorToken = 'vt_other1';
        $this->service()->slots();
        self::assertCount(2, $this->engineCalls, 'another shopper has their own entry');
    }

    public function testAnEmptyAnswerIsCachedAndNotAskedAgain(): void
    {
        $this->guest('vt_8f3k2a');

        $this->service()->slots();
        $this->service()->slots();

        self::assertCount(1, $this->engineCalls);
        self::assertSame('[]', $this->cacheEntry()['value']);
    }

    public function testARefusalOfOneShopperIsCachedForTenMinutesWithoutAPause(): void
    {
        $this->guest('vt_8f3k2a');
        $this->engineAnswer = new EngineRequestException('Engine request failed with HTTP 400', 400);

        self::assertSame([], $this->service()->slots());

        self::assertSame(['value' => '[]', 'ttl' => 600], $this->cacheEntry());
        self::assertArrayNotHasKey('smaily_recs_paused', $this->cacheStore);
    }

    /**
     * @dataProvider unavailableProvider
     */
    public function testAnUnavailableEnginePausesEveryShoppersCallsForTwoMinutes(int $status): void
    {
        $this->guest('vt_8f3k2a');
        $this->engineAnswer = new EngineTransportException('Engine request failed', $status);

        $this->service()->slots();
        self::assertSame(['value' => '1', 'ttl' => 120], $this->cacheStore['smaily_recs_paused']);

        $this->visitorToken = 'vt_other1';
        self::assertSame([], $this->service()->slots());
        self::assertCount(1, $this->engineCalls, 'another shopper\'s miss does not call the engine');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function unavailableProvider(): array
    {
        return [
            'a timeout or network failure' => [0],
            'a server error' => [503],
        ];
    }

    public function testOnlyUsableSlotsAreKeptInTheEnginesOrderUpToFour(): void
    {
        $this->guest('vt_8f3k2a');
        $slot = static fn (string $recId, mixed $externalId, mixed $sku): array => [
            'rec_id' => $recId, 'external_id' => $externalId, 'sku' => $sku,
        ];
        $this->engineAnswer = ['slots' => [
            $slot('not-a-uuid', '1', 'A'),
            $slot(self::REC_ID, null, 'MJ02'),
            $slot('8b2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c', null, null),
            $slot('9b2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c', 9, 'MJ09'),
            'not a slot',
            $slot('ab2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c', '10', 'MJ10'),
            $slot('bb2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c', '11', 'MJ11'),
            $slot('cb2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c', '12', 'MJ12'),
            $slot('db2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c', '13', 'MJ13'),
        ]];

        $slots = $this->service()->slots();

        self::assertSame([
            ['rec_id' => self::REC_ID, 'external_id' => '', 'sku' => 'MJ02'],
            ['rec_id' => '9b2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c', 'external_id' => '9', 'sku' => 'MJ09'],
            ['rec_id' => 'ab2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c', 'external_id' => '10', 'sku' => 'MJ10'],
            ['rec_id' => 'bb2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c', 'external_id' => '11', 'sku' => 'MJ11'],
        ], $slots);
    }

    private function loggedIn(int $customerId, string $email, bool $profilingAllowed): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn($email);
        $this->session->method('isLoggedIn')->willReturn(true);
        $this->session->method('getCustomerId')->willReturn($customerId);
        $this->session->method('getCustomerData')->willReturn($customer);
        $this->profiling->method('isAllowed')->with($email, 3)->willReturn($profilingAllowed);
    }

    private function guest(?string $visitorToken, bool $cookieNoticeAllows = true): void
    {
        $this->session->method('isLoggedIn')->willReturn(false);
        $this->cookieHelper->method('isUserNotAllowSaveCookie')->willReturn(!$cookieNoticeAllows);
        $this->visitorToken = $visitorToken;
    }

    /**
     * @return array{value: string, ttl: int|null}
     */
    private function cacheEntry(): array
    {
        $entries = array_filter(
            $this->cacheStore,
            static fn (string $key): bool => $key !== 'smaily_recs_paused',
            ARRAY_FILTER_USE_KEY
        );
        self::assertCount(1, $entries);

        return reset($entries);
    }

    private function service(?Settings $settings = null): StorefrontRecommendations
    {
        $attribution = $this->createMock(AttributionManager::class);
        $attribution->method('readCookies')->willReturnCallback(fn (): array => [
            'rec_id' => null,
            'visitor_token' => $this->visitorToken,
            'rec_ctx' => null,
            'anon_session_id' => null,
        ]);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

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

        return new StorefrontRecommendations(
            $settings ?? $this->settings,
            $this->client,
            $this->profiling,
            $attribution,
            $this->session,
            $this->cookieHelper,
            $storeManager,
            $cache,
            $this->createMock(Logger::class)
        );
    }
}
