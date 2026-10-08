<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Controller\Recommendations;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Result\Layout;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Recommendations\Index;
use Smaily\Connect\Model\Engine\RecommendedProducts;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\StorefrontRecommendations;
use Smaily\Connect\Model\RateLimit\FixedWindowCounter;
use Smaily\Connect\Model\RateLimit\PerAddressLimiter;

/**
 * PRO-3790: the store route the recommendations script asks — its guards,
 * and an answer no shared cache may keep.
 */
class IndexTest extends TestCase
{
    /** @var array{code: int, contents: string|null, headers: array<string, string>, block: mixed} */
    private array $answer = ['code' => 200, 'contents' => null, 'headers' => [], 'block' => null];

    private int $slotCalls = 0;

    /** @var array<string, string> */
    private array $cacheStore = [];

    public function testAStoreThatMayNotCallTheEngineAnswersABare404(): void
    {
        $this->controller(sendingAllowed: false)->execute();

        self::assertSame(404, $this->answer['code']);
        self::assertSame('', $this->answer['contents']);
        self::assertSame('no-store, private', $this->answer['headers']['Cache-Control']);
        self::assertSame(0, $this->slotCalls);
    }

    public function testTheLimitPerAddressHolds(): void
    {
        $controller = $this->controller();
        for ($i = 0; $i < 120; $i++) {
            $controller->execute();
            self::assertSame(200, $this->answer['code'], 'request ' . ($i + 1) . ' is within the limit');
        }

        $controller->execute();

        self::assertSame(429, $this->answer['code']);
        self::assertSame(120, $this->slotCalls, 'the limited request asks for nothing');
    }

    /**
     * @dataProvider otherSiteProvider
     */
    public function testARequestAnotherSiteMakesGetsAnEmptyAnswer(string $site): void
    {
        $this->controller(secFetchSite: $site)->execute();

        self::assertSame(200, $this->answer['code']);
        self::assertSame('', $this->answer['contents']);
        self::assertSame(0, $this->slotCalls);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherSiteProvider(): array
    {
        return ['cross-site' => ['cross-site'], 'same-site' => ['same-site']];
    }

    public function testNothingToShowIsAnEmptyAnswer(): void
    {
        $this->controller(secFetchSite: 'same-origin')->execute();

        self::assertSame(200, $this->answer['code']);
        self::assertSame('', $this->answer['contents']);
        self::assertSame(1, $this->slotCalls);
        self::assertNull($this->answer['block']);
    }

    public function testTheCardsAreRenderedByTheLayoutBlockAndNeverKeptByASharedCache(): void
    {
        $items = [['product' => $this->createMock(Product::class), 'url' => 'https://shop.example/a.html']];

        $result = $this->controller(items: $items)->execute();

        self::assertInstanceOf(Layout::class, $result);
        self::assertSame($items, $this->answer['block']);
        self::assertSame('no-store, private', $this->answer['headers']['Cache-Control']);
    }

    /**
     * @param array<int, array{product: Product, url: string}> $items
     */
    private function controller(
        bool $sendingAllowed = true,
        string $secFetchSite = '',
        array $items = []
    ): Index {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getHeader')->with('Sec-Fetch-Site')
            ->willReturn($secFetchSite === '' ? false : $secFetchSite);

        $raw = $this->createMock(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($raw) {
            $this->answer['code'] = $code;

            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function (string $contents) use ($raw) {
            $this->answer['contents'] = $contents;

            return $raw;
        });
        $raw->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($raw) {
            $this->answer['headers'][$name] = $value;

            return $raw;
        });

        $block = $this->createMock(AbstractBlock::class);
        $block->method('setData')->willReturnCallback(function (string $key, mixed $value) use ($block) {
            $this->answer['block'] = $value;

            return $block;
        });
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->with(Index::BLOCK_NAME)->willReturn($block);
        $layoutResult = $this->createMock(Layout::class);
        $layoutResult->method('getLayout')->willReturn($layout);
        $layoutResult->method('setHeader')->willReturnCallback(
            function (string $name, string $value) use ($layoutResult) {
                $this->answer['headers'][$name] = $value;

                return $layoutResult;
            }
        );

        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->willReturnCallback(
            function (string $type) use ($raw, $layoutResult) {
                $this->answer = ['code' => 200, 'contents' => null, 'headers' => [], 'block' => null];

                return $type === ResultFactory::TYPE_LAYOUT ? $layoutResult : $raw;
            }
        );

        $settings = $this->createMock(Settings::class);
        $settings->method('isSendingAllowed')->willReturn($sendingAllowed);

        $recommendations = $this->createMock(StorefrontRecommendations::class);
        $recommendations->method('slots')->willReturnCallback(function (): array {
            $this->slotCalls++;

            return [];
        });
        $products = $this->createMock(RecommendedProducts::class);
        $products->method('forSlots')->willReturn($items);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key): string|false => $this->cacheStore[$key] ?? false);
        $cache->method('save')->willReturnCallback(function (string $value, string $key): bool {
            $this->cacheStore[$key] = $value;

            return true;
        });
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_760_000_000);
        $remoteAddress = $this->createMock(RemoteAddress::class);
        $remoteAddress->method('getRemoteAddress')->willReturn('203.0.113.7');

        return new Index(
            $request,
            $resultFactory,
            $settings,
            $recommendations,
            $products,
            new PerAddressLimiter(new FixedWindowCounter($cache), $dateTime, $remoteAddress)
        );
    }
}
