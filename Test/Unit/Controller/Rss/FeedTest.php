<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Controller\Rss;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Rss\Feed;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Rss\FeedBuilder;

/**
 * The public feed is cached by the parameters as the feed applies them, so
 * varying an invalid value cannot force a rebuild, and a parameter that is
 * not a single string is treated as absent rather than raising an error.
 */
class FeedTest extends TestCase
{
    /** @var string[] */
    private array $cacheKeys = [];

    /** @var array<int, array{int, string, string}> */
    private array $builds = [];

    /**
     * @dataProvider sameFeedProvider
     * @param array<string, mixed> $query
     */
    public function testInvalidValuesShareTheDefaultFeedsCacheEntry(array $query): void
    {
        $defaultFeed = $this->feed([]);
        $variedFeed = $this->feed($query);
        // Magento turns a PHP warning into an error page; so does this test.
        set_error_handler(static function (int $level, string $message): bool {
            throw new \ErrorException($message, 0, $level);
        }, E_WARNING);
        try {
            $defaultFeed->execute();
            $variedFeed->execute();
        } finally {
            restore_error_handler();
        }

        self::assertCount(2, $this->cacheKeys);
        self::assertSame($this->cacheKeys[0], $this->cacheKeys[1]);
        self::assertSame([50, 'created_at', 'DESC'], $this->builds[1]);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function sameFeedProvider(): array
    {
        return [
            'non-numeric limit, unknown sort and order' => [
                ['limit' => 'abc', 'sort' => 'nope', 'order' => 'sideways'],
            ],
            'zero limit' => [['limit' => '0']],
            'spelled-out defaults' => [['limit' => '50', 'sort' => 'created_at', 'order' => 'DESC']],
            'array-typed values' => [['limit' => ['250'], 'sort' => ['price'], 'order' => ['asc']]],
        ];
    }

    public function testAnOutOfRangeLimitSharesTheMaximumsCacheEntry(): void
    {
        $this->feed(['limit' => '250'])->execute();
        $this->feed(['limit' => '99999'])->execute();

        self::assertSame($this->cacheKeys[0], $this->cacheKeys[1]);
        self::assertSame([250, 'created_at', 'DESC'], $this->builds[1]);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function feed(array $query): Feed
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $name): mixed => $query[$name] ?? null
        );

        require_once __DIR__ . '/../../Support/Stub/RawFactory.php';
        $raw = $this->createMock(Raw::class);
        $raw->method('setHeader')->willReturnSelf();
        $raw->method('setContents')->willReturnSelf();
        $rawFactory = $this->createMock(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $builder = $this->createMock(FeedBuilder::class);
        $builder->method('build')->willReturnCallback(
            function (mixed $store, ?int $category, int $limit, string $sort, string $order): string {
                $this->builds[] = [$limit, $sort, $order];

                return '<rss/>';
            }
        );

        $config = $this->createMock(Config::class);
        $config->method('isRssEnabled')->willReturn(true);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(function (string $key): bool {
            $this->cacheKeys[] = $key;

            return false;
        });

        return new Feed($request, $rawFactory, $storeManager, $builder, $config, $cache);
    }
}
