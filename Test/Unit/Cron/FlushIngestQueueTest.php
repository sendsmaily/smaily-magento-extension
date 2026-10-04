<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Cron;

use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Cron\FlushIngestQueue;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;

class FlushIngestQueueTest extends TestCase
{
    private IngestQueue&MockObject $queue;
    private Client&MockObject $client;
    private Settings&MockObject $settings;
    private CatalogIngest&MockObject $catalogIngest;

    /** @var array<int, array<string, mixed>> payloads by event id (decodePayload stub) */
    private array $payloads = [];

    protected function setUp(): void
    {
        $this->queue = $this->createMock(IngestQueue::class);
        $this->client = $this->createMock(Client::class);
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isSendingAllowed')->willReturn(true);
        $this->catalogIngest = $this->createMock(CatalogIngest::class);
        $this->queue->method('decodePayload')->willReturnCallback(
            fn (IngestEvent $event): array => $this->payloads[(int)$event->getId()] ?? ['sku' => 'X']
        );
    }

    public function testD6ErrorsMapBackOntoBatchRowsByIndex(): void
    {
        $first = $this->createEvent(11);
        $second = $this->createEvent(12);
        $third = $this->createEvent(13);
        $this->stubClaims([Client::DOMAIN_CATALOG => [$first, $second, $third]]);

        $this->client->method('ingest')->willReturn([
            'ok' => true,
            'processed' => 2,
            'deduplicated' => 0,
            'errors' => [
                ['index' => 1, 'field' => 'price', 'message' => 'must be a number', 'sku' => 'X'],
            ],
        ]);

        $sent = [];
        $this->queue->method('markSent')->willReturnCallback(
            static function (IngestEvent $event) use (&$sent): void {
                $sent[] = (int)$event->getId();
            }
        );
        $failed = [];
        $this->queue->method('markFailed')->willReturnCallback(
            static function (IngestEvent $event, string $error, bool $terminal = false) use (&$failed): void {
                $failed[(int)$event->getId()] = [$error, $terminal];
            }
        );

        $this->createCron()->execute();

        self::assertSame([11, 13], $sent);
        self::assertArrayHasKey(12, $failed);
        self::assertSame('price: must be a number', $failed[12][0]);
        self::assertTrue($failed[12][1], 'Per-item validation errors are terminal');
    }

    public function testTransportFailureReschedulesBatchNonTerminally(): void
    {
        $event = $this->createEvent(5);
        $this->stubClaims([Client::DOMAIN_CATALOG => [$event]]);
        $this->client->method('ingest')
            ->willThrowException(new EngineTransportException('engine down', 503));

        $this->queue->expects(self::once())->method('markFailed')
            ->with($event, 'engine down');
        $this->queue->expects(self::never())->method('markSent');

        $this->createCron()->execute();
    }

    public function testWholeBatchRequestErrorIsTerminal(): void
    {
        $event = $this->createEvent(6);
        $this->stubClaims([Client::DOMAIN_BROWSE => [$event]]);
        $this->client->method('ingest')
            ->willThrowException(new EngineRequestException('bad wrapper', 400));

        $this->queue->expects(self::once())->method('markFailed')
            ->with($event, 'bad wrapper', true);

        $this->createCron()->execute();
    }

    /**
     * Contract §2 / PRO-2451: the account was refused mid-flush, so the rows
     * are not at fault. They go back to pending exactly as they were — never
     * burned, never failed, attempt counters untouched (Erkki, 2026-09-10) —
     * and the rest of the run is abandoned instead of collecting more 403s.
     */
    public function testARefusedAccountReleasesTheBatchAndEndsTheRun(): void
    {
        $event = $this->createEvent(7);
        $this->stubClaims([Client::DOMAIN_CATALOG => [$event]]);
        // Mutable holder: the refusal is learned DURING the run, exactly as
        // Engine\Client records it mid-batch.
        $account = new class {
            public bool $refused = false;
        };
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isSendingAllowed')->willReturnCallback(
            static fn (): bool => !$account->refused
        );
        $this->settings->method('isRefused')->willReturnCallback(
            static fn (): bool => $account->refused
        );
        $this->client->method('ingest')->willReturnCallback(
            static function () use ($account): array {
                // What Engine\Client does on a `403 tenant_inactive`.
                $account->refused = true;

                throw new EngineRequestException('HTTP 403: tenant_inactive', 403);
            }
        );

        $this->queue->expects(self::once())->method('release')->with([$event]);
        $this->queue->expects(self::never())->method('markFailed');
        $this->queue->expects(self::never())->method('markSent');
        // catalog is the first domain: customers/orders/browse/catalog_remove
        // are never even claimed once the refusal is known.
        $this->queue->expects(self::once())->method('claimBatch');

        $this->createCron()->execute();
    }

    public function testDisconnectedEngineSkipsAllWork(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isSendingAllowed')->willReturn(false);
        $this->queue->expects(self::never())->method('claimBatch');
        $this->catalogIngest->expects(self::never())->method('buildChanged');

        (new FlushIngestQueue(
            $settings,
            $this->queue,
            $this->catalogIngest,
            $this->client,
            new Json(),
            $this->createMock(Logger::class)
        ))->execute();
    }

    /**
     * PRO-1967: the stock hooks only mark products changed; each run builds
     * their rows before it claims the catalog batch, so they go out in the
     * same run (the ~1–2 min latency holds).
     */
    public function testChangedProductsAreBuiltBeforeTheCatalogBatchIsClaimed(): void
    {
        $calls = [];
        $this->catalogIngest->expects(self::once())->method('buildChanged')->willReturnCallback(
            static function () use (&$calls): void {
                $calls[] = 'build';
            }
        );
        $this->queue->method('claimBatch')->willReturnCallback(
            static function (string $domain) use (&$calls): array {
                $calls[] = $domain;

                return [];
            }
        );

        $this->createCron()->execute();

        self::assertSame(['build', Client::DOMAIN_CATALOG], array_slice($calls, 0, 2));
    }

    /**
     * A catalog build that throws must not hold up the customer, order and
     * browse deliveries of the run; its markers come back via requeueStale().
     */
    public function testAFailedCatalogBuildStillLetsTheRunSend(): void
    {
        $this->catalogIngest->method('buildChanged')->willThrowException(new \RuntimeException('db gone'));
        $event = $this->createEvent(8, Client::DOMAIN_ORDERS);
        $this->stubClaims([Client::DOMAIN_ORDERS => [$event]]);
        $this->client->method('ingest')->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);

        $this->queue->expects(self::once())->method('markSent')->with($event);

        $this->createCron()->execute();
    }

    /**
     * §3b (PRO-1231): one wrapper of UNIQUE product ids; not D6 — a 2xx
     * applies to every id, `not_found` is a contract-defined success
     * recorded as the row's outcome, never a retry.
     */
    public function testCatalogRemoveSendsUniqueIdsAndRecordsPerRowOutcomes(): void
    {
        $first = $this->createEvent(21, Client::DOMAIN_CATALOG_REMOVE);
        $duplicate = $this->createEvent(22, Client::DOMAIN_CATALOG_REMOVE);
        $gone = $this->createEvent(23, Client::DOMAIN_CATALOG_REMOVE);
        $this->payloads = [
            21 => ['product_id' => '7'],
            22 => ['product_id' => '7'],
            23 => ['product_id' => '9'],
        ];
        $this->stubClaims([Client::DOMAIN_CATALOG_REMOVE => [$first, $duplicate, $gone]]);

        $this->client->expects(self::never())->method('ingest');
        $this->client->expects(self::once())->method('catalogRemove')
            ->with(['7', '9'])
            ->willReturn([
                'ok' => true,
                'removed_products' => 1,
                'rows_tombstoned' => 3,
                'not_found' => ['9'],
            ]);

        $sent = [];
        $this->queue->method('markSent')->willReturnCallback(
            static function (IngestEvent $event, ?string $response = null) use (&$sent): void {
                $sent[(int)$event->getId()] = (array)json_decode((string)$response, true);
            }
        );
        $this->queue->expects(self::never())->method('markFailed');

        $this->createCron()->execute();

        self::assertSame(['removed', 'removed', 'not_found'], array_column([$sent[21], $sent[22], $sent[23]], 'outcome'));
        self::assertSame(3, $sent[21]['rows_tombstoned']);
    }

    public function testCatalogRemoveRowWithoutKeyIsTerminalObservableSkip(): void
    {
        $keyless = $this->createEvent(31, Client::DOMAIN_CATALOG_REMOVE);
        $this->payloads = [31 => ['event_id' => 'u-31']];
        $this->stubClaims([Client::DOMAIN_CATALOG_REMOVE => [$keyless]]);

        $this->client->expects(self::never())->method('catalogRemove');
        $this->queue->expects(self::once())->method('markFailed')
            ->with($keyless, 'catalog/remove row has no product_id', true);

        $this->createCron()->execute();
    }

    public function testCatalogRemoveTransportFailureReschedulesNonTerminally(): void
    {
        $event = $this->createEvent(41, Client::DOMAIN_CATALOG_REMOVE);
        $this->payloads = [41 => ['product_id' => '7']];
        $this->stubClaims([Client::DOMAIN_CATALOG_REMOVE => [$event]]);
        $this->client->method('catalogRemove')
            ->willThrowException(new EngineTransportException('engine down', 503));

        $this->queue->expects(self::once())->method('markFailed')
            ->with($event, 'engine down');
        $this->queue->expects(self::never())->method('markSent');

        $this->createCron()->execute();
    }

    public function testCatalogRemoveRequestErrorIsTerminal(): void
    {
        // A 404 here means the engine predates §3b ("not yet available") —
        // parked, never retried; the periodic full re-sync reconciles.
        $event = $this->createEvent(51, Client::DOMAIN_CATALOG_REMOVE);
        $this->payloads = [51 => ['product_id' => '7']];
        $this->stubClaims([Client::DOMAIN_CATALOG_REMOVE => [$event]]);
        $this->client->method('catalogRemove')
            ->willThrowException(new EngineRequestException('HTTP 404: not found', 404));

        $this->queue->expects(self::once())->method('markFailed')
            ->with($event, 'HTTP 404: not found', true);

        $this->createCron()->execute();
    }

    /**
     * @param array<string, IngestEvent[]> $byDomain
     */
    private function stubClaims(array $byDomain): void
    {
        $this->queue->method('claimBatch')->willReturnCallback(
            static fn (string $domain): array => $byDomain[$domain] ?? []
        );
    }

    private function createCron(): FlushIngestQueue
    {
        return new FlushIngestQueue(
            $this->settings,
            $this->queue,
            $this->catalogIngest,
            $this->client,
            new Json(),
            $this->createMock(Logger::class)
        );
    }

    private function createEvent(int $id, string $domain = 'catalog'): IngestEvent&MockObject
    {
        $event = $this->createMock(IngestEvent::class);
        $event->method('getId')->willReturn($id);
        $event->method('getDomain')->willReturn($domain);

        return $event;
    }
}
