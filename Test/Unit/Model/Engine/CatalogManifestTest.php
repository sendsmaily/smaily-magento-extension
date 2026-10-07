<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\CatalogManifest;
use Smaily\Connect\Model\Engine\CatalogProductLoader;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;

/**
 * PRO-3854: the nightly catalog manifest — when it is not sent, how the
 * list is read, and the one Log row a send leaves.
 */
class CatalogManifestTest extends TestCase
{
    private Settings&MockObject $settings;
    private JobManager&MockObject $jobManager;
    private IngestQueue&MockObject $queue;
    private CatalogProductLoader&MockObject $loader;
    private CatalogPayloadBuilder&MockObject $builder;
    private StockRegistryStorage&MockObject $stockStorage;
    private Client&MockObject $client;
    private Logger&MockObject $logger;
    private IngestEvent&MockObject $event;

    /** @var array<string, mixed> FlagManager's flags as the manifest leaves them */
    private array $flags = [];

    /** @var array<string, int> undelivered rows per queue domain */
    private array $pending = [];

    /** @var int[] the cursor each page was read after */
    private array $cursors = [];

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isSendingAllowed')->willReturn(true);
        $this->jobManager = $this->createMock(JobManager::class);
        $this->queue = $this->createMock(IngestQueue::class);
        $this->queue->method('countPending')->willReturnCallback(
            fn (string $domain): int => $this->pending[$domain] ?? 0
        );
        $this->event = $this->createMock(IngestEvent::class);
        $this->loader = $this->createMock(CatalogProductLoader::class);
        $this->builder = $this->createMock(CatalogPayloadBuilder::class);
        $this->builder->method('manifestItem')->willReturnCallback(
            static fn (Product $product): ?array => $product->getStatus() === 2
                ? null
                : ['sku' => 'SKU-' . $product->getId(), 'in_stock' => $product->getId() % 2 === 1]
        );
        $this->stockStorage = $this->createMock(StockRegistryStorage::class);
        $this->client = $this->createMock(Client::class);
        $this->client->method('lastExchange')->willReturnCallback(static fn (): array => [
            'request' => [],
            'response' => ['http_status' => 200, 'body' => ['ok' => true, 'removed' => 3]],
        ]);
        $this->logger = $this->createMock(Logger::class);
    }

    /**
     * PRO-3914: each night no list goes out counts one more night, with
     * the reason, for the Dashboard; a list sent clears the count, and so
     * does a night when no list is due.
     *
     * @param array{nights: int, reason: string}|null $before
     * @param array{nights: int, reason: string}|null $after
     * @dataProvider unsentNights
     */
    public function testTheNightsWithoutAListAreCountedWithTheLastReason(
        string $night,
        ?array $before,
        ?array $after
    ): void {
        if ($before !== null) {
            $this->flags[CatalogManifest::FLAG_UNSENT] = $before;
        }
        $this->queue->method('newEvent')->willReturn($this->event);
        switch ($night) {
            case 'not allowed':
                $this->settings = $this->createMock(Settings::class);
                break;
            case 'import':
                $this->jobManager->method('isActiveAndMoving')->willReturn(true);
                break;
            case 'queue':
                $this->pending[Client::DOMAIN_CATALOG] = 1;
                break;
            case 'build':
                $this->loader->method('loadForManifest')->willThrowException(new \RuntimeException('Lost connection'));
                break;
            case 'too many':
                // Pages of 1,000 without end, as in the over-the-limit test.
                $id = 0;
                $product = $this->createMock(Product::class);
                $product->method('getId')->willReturnCallback(static function () use (&$id): int {
                    return ++$id;
                });
                $this->loader->method('loadForManifest')->willReturn(array_fill(0, 1000, $product));
                $this->builder = $this->createMock(CatalogPayloadBuilder::class);
                $this->builder->method('manifestItem')->willReturn(['sku' => 'SKU', 'in_stock' => true]);
                break;
            case 'failed':
                $this->catalog([[$this->product(1)]]);
                $this->client->method('catalogManifest')
                    ->willThrowException(new EngineRequestException('Engine request failed with HTTP 400', 400));
                break;
            default:
                $this->catalog([[$this->product(1)]]);
                $this->client->method('catalogManifest')->willReturn(['ok' => true]);
        }

        $this->manifest()->send();

        self::assertSame($after, $this->flags[CatalogManifest::FLAG_UNSENT] ?? null);
    }

    /**
     * @return array<string, array{string, array{nights: int, reason: string}|null, array{nights: int, reason: string}|null}>
     */
    public static function unsentNights(): array
    {
        $two = ['nights' => 2, 'reason' => CatalogManifest::REASON_IMPORT];

        return [
            'sent after two nights without' => ['sent', $two, null],
            'not allowed: no list is due' => ['not allowed', $two, null],
            'import, a third night' => ['import', $two, ['nights' => 3, 'reason' => CatalogManifest::REASON_IMPORT]],
            'queue, a first night' => ['queue', null, ['nights' => 1, 'reason' => CatalogManifest::REASON_QUEUE]],
            'build' => ['build', $two, ['nights' => 3, 'reason' => CatalogManifest::REASON_BUILD]],
            'too many' => ['too many', null, ['nights' => 1, 'reason' => CatalogManifest::REASON_TOO_MANY]],
            'failed' => ['failed', $two, ['nights' => 3, 'reason' => CatalogManifest::REASON_FAILED]],
        ];
    }

    public function testTheWholeCatalogIsReadPageByPageAndSentInOneRequest(): void
    {
        // Three products, one disabled; pages end only at an empty one.
        $this->catalog([[$this->product(1), $this->product(2, disabled: true)], [$this->product(3)]]);
        $this->stockStorage->expects(self::exactly(3))->method('clean');
        $this->client->expects(self::once())->method('catalogManifest')->with([
            ['sku' => 'SKU-1', 'in_stock' => true],
            ['sku' => 'SKU-3', 'in_stock' => true],
        ])->willReturn(['ok' => true]);
        $this->queue->expects(self::once())->method('newEvent')
            ->with(CatalogManifest::DOMAIN, ['products' => 2, 'in_stock' => 2])
            ->willReturn($this->event);
        $this->queue->expects(self::once())->method('recordExchange')->with(
            $this->event,
            ['products' => [['sku' => 'SKU-1', 'in_stock' => true], ['sku' => 'SKU-3', 'in_stock' => true]]],
            ['http_status' => 200, 'body' => ['ok' => true, 'removed' => 3]]
        );
        $this->queue->expects(self::once())->method('markSent')->with($this->event);
        $this->queue->expects(self::never())->method('markFailed');

        $this->manifest()->send();

        self::assertSame([0, 2, 3], $this->cursors);
    }

    public function testTheLogRowKeepsASampleOfTheListAndCountsTheRest(): void
    {
        $products = [];
        for ($id = 1; $id <= 25; $id++) {
            $products[] = $this->product($id);
        }
        $this->catalog([$products]);
        $this->client->method('catalogManifest')->willReturn(['ok' => true]);
        $this->queue->method('newEvent')->willReturn($this->event);
        $this->queue->expects(self::once())->method('recordExchange')->with(
            $this->event,
            self::callback(static fn (array $sent): bool => count($sent['products']) === 20
                && $sent['products'][0] === ['sku' => 'SKU-1', 'in_stock' => true]
                && $sent['products_not_shown'] === 5),
            self::anything()
        );

        $this->manifest()->send();
    }

    /**
     * Contract §3c: a store over 50,000 products must not send a partial
     * list. Nothing is sent; the Log row says why, in the merchant's words.
     */
    public function testAStoreOverTheLimitSendsNothingAndTheLogSaysWhy(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturnCallback(static function (): int {
            static $id = 0;
            return ++$id;
        });
        $page = array_fill(0, 1000, $product);
        $this->loader->method('loadForManifest')->willReturn($page);
        $this->builder = $this->createMock(CatalogPayloadBuilder::class);
        $this->builder->method('manifestItem')->willReturn(['sku' => 'SKU', 'in_stock' => true]);
        $this->client->expects(self::never())->method('catalogManifest');
        $this->queue->expects(self::once())->method('newEvent')
            ->with(CatalogManifest::DOMAIN, ['limit' => 50000])
            ->willReturn($this->event);
        $this->queue->expects(self::once())->method('markFailed')
            ->with($this->event, CatalogManifest::TOO_MANY_PRODUCTS, true);

        $this->manifest()->send();
    }

    /**
     * A failed send is not tried again later: the next night builds a new
     * list. The row is failed at once, with what went over the wire.
     *
     * @dataProvider engineFailures
     */
    public function testAFailedSendIsRecordedOnceAndNotRetried(\Exception $failure): void
    {
        $this->catalog([[$this->product(1)]]);
        $this->client->method('catalogManifest')->willThrowException($failure);
        $this->queue->method('newEvent')->willReturn($this->event);
        $this->queue->expects(self::once())->method('recordExchange');
        $this->queue->expects(self::once())->method('markFailed')->with($this->event, $failure->getMessage(), true);
        $this->queue->expects(self::never())->method('markSent');

        $this->manifest()->send();
    }

    /**
     * @return array<string, array{0: \Exception}>
     */
    public static function engineFailures(): array
    {
        return [
            'engine down after the retries' => [new EngineTransportException('Engine request failed with HTTP 503 after retries', 503)],
            'list refused' => [new EngineRequestException('Engine request failed with HTTP 400: validation_failed', 400)],
        ];
    }

    /**
     * @dataProvider skips
     */
    public function testNothingIsReadOrSentWhileAPartialOrPrematureListCouldRemoveProducts(
        bool $sendingAllowed,
        bool $importInProgress,
        string $pendingDomain
    ): void {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isSendingAllowed')->willReturn($sendingAllowed);
        $this->jobManager->method('isActiveAndMoving')
            ->with(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID, CatalogManifest::STALLED_IMPORT_SECONDS)
            ->willReturn($importInProgress);
        if ($pendingDomain !== '') {
            $this->pending[$pendingDomain] = 1;
        }
        $this->loader->expects(self::never())->method('loadForManifest');
        $this->client->expects(self::never())->method('catalogManifest');
        $this->queue->expects(self::never())->method('newEvent');
        $this->logger->expects(self::once())->method('info')
            ->with('Nightly catalog manifest not sent', self::arrayHasKey('reason'));

        $this->manifest()->send();
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: string}>
     */
    public static function skips(): array
    {
        return [
            'not connected, or refused' => [false, false, ''],
            'catalog import queued or running' => [true, true, ''],
            'catalog rows on their way' => [true, false, Client::DOMAIN_CATALOG],
            'product removals on their way' => [true, false, Client::DOMAIN_CATALOG_REMOVE],
            'stock changes not built yet' => [true, false, CatalogIngest::DOMAIN_CHANGED],
        ];
    }

    public function testAListThatCannotBeBuiltIsNotSent(): void
    {
        $this->loader->method('loadForManifest')->willThrowException(new \RuntimeException('Lost connection'));
        $this->client->expects(self::never())->method('catalogManifest');
        $this->queue->expects(self::never())->method('newEvent');
        $this->logger->expects(self::once())->method('error');

        $this->manifest()->send();
    }

    /**
     * @param array<int, Product[]> $pages the pages before the empty one
     */
    private function catalog(array $pages): void
    {
        $pages[] = [];
        $this->loader->method('loadForManifest')->willReturnCallback(
            function (int $afterId, int $pageSize) use (&$pages): array {
                $this->cursors[] = $afterId;
                self::assertSame(1000, $pageSize);

                return array_shift($pages);
            }
        );
    }

    private function product(int $id, bool $disabled = false): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getStatus')->willReturn($disabled ? 2 : 1);

        return $product;
    }

    private function manifest(): CatalogManifest
    {
        return new CatalogManifest(
            $this->settings,
            $this->jobManager,
            $this->queue,
            $this->loader,
            $this->builder,
            $this->stockStorage,
            $this->client,
            $this->logger,
            $this->flagManager()
        );
    }

    private function flagManager(): FlagManager&MockObject
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturnCallback(fn (string $code) => $this->flags[$code] ?? null);
        $flagManager->method('saveFlag')->willReturnCallback(function (string $code, $data) use ($flagManager) {
            $this->flags[$code] = $data;

            return $flagManager;
        });
        $flagManager->method('deleteFlag')->willReturnCallback(function (string $code) use ($flagManager) {
            unset($this->flags[$code]);

            return $flagManager;
        });

        return $flagManager;
    }
}
