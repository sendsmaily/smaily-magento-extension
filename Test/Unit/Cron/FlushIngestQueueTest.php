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
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\IngestPace;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\OptOutReplay;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

class FlushIngestQueueTest extends TestCase
{
    use EngineConnectionsStub;

    private IngestQueue&MockObject $queue;
    private Client&MockObject $client;
    private Settings&MockObject $settings;
    private CatalogIngest&MockObject $catalogIngest;
    private ProfilingConsent&MockObject $profilingConsent;
    private IngestPace&MockObject $pace;
    private JobManager&MockObject $jobManager;

    /** @var string[] the domains claimBatch was asked for, in order */
    private array $claims = [];

    /** @var string[] "<connection key>:<domain>" claimBatch was asked for, in order */
    private array $connectionClaims = [];

    /** @var array<int, array<string, mixed>> payloads by event id (decodePayload stub) */
    private array $payloads = [];

    protected function setUp(): void
    {
        $this->queue = $this->createMock(IngestQueue::class);
        $this->client = $this->createMock(Client::class);
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isSendingAllowed')->willReturn(true);
        $this->catalogIngest = $this->createMock(CatalogIngest::class);
        $this->profilingConsent = $this->createMock(ProfilingConsent::class);
        $this->pace = $this->createMock(IngestPace::class);
        $this->pace->method('hasTime')->willReturn(true);
        $this->jobManager = $this->createMock(JobManager::class);
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
        $this->queue->method('markFailedMany')->willReturnCallback(
            static function (array $events, string $error, bool $terminal = false) use (&$failed): void {
                foreach ($events as $event) {
                    $failed[(int)$event->getId()] = [$error, $terminal];
                }
            }
        );

        $this->createCron()->execute();

        self::assertSame([11, 13], $sent);
        self::assertArrayHasKey(12, $failed);
        self::assertSame('price: must be a number', $failed[12][0]);
        self::assertTrue($failed[12][1], 'Per-item validation errors are terminal');
    }

    /**
     * Contract §5 (v1.20.0): an order older than the history horizon is
     * answered 200 and counted in `skipped`, not in `errors[]`. Its row is
     * marked sent like the others, and the count holds, so no mismatch is
     * logged.
     */
    public function testOrdersTheEngineSkipsAreMarkedSentAndKeepTheCountWhole(): void
    {
        $first = $this->createEvent(41, Client::DOMAIN_ORDERS);
        $second = $this->createEvent(42, Client::DOMAIN_ORDERS);
        $this->stubClaims([Client::DOMAIN_ORDERS => [$first, $second]]);
        $this->client->method('ingest')->willReturn([
            'ok' => true,
            'processed' => 1,
            'deduplicated' => 0,
            'errors' => [],
            'skipped' => 1,
            'skipped_orders' => [
                ['index' => 0, 'external_order_id' => '100', 'reason' => 'older_than_retention'],
            ],
        ]);

        $sent = [];
        $this->queue->method('markSent')->willReturnCallback(
            static function (IngestEvent $event) use (&$sent): void {
                $sent[] = (int)$event->getId();
            }
        );
        $this->queue->expects(self::never())->method('markFailedMany');
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::never())->method('info');

        $this->createCron($logger)->execute();

        self::assertSame([41, 42], $sent);
    }

    /**
     * CON-241: an import's rows the engine accepted are counted on the job
     * that queued them; a row refused per item, an order skipped as older
     * than the horizon and a live row (no job) are not.
     */
    public function testTheRowsTheEngineAcceptedAreCountedOnTheirImportJob(): void
    {
        $events = [
            $this->createEvent(51, Client::DOMAIN_ORDERS, 7),
            $this->createEvent(52, Client::DOMAIN_ORDERS, 7),
            $this->createEvent(53, Client::DOMAIN_ORDERS, 7),
            $this->createEvent(54, Client::DOMAIN_ORDERS, 7),
            $this->createEvent(55, Client::DOMAIN_ORDERS),
            $this->createEvent(56, Client::DOMAIN_ORDERS, 8),
        ];
        $this->stubClaims([Client::DOMAIN_ORDERS => $events]);
        $this->client->method('ingest')->willReturn([
            'ok' => true,
            'processed' => 4,
            'deduplicated' => 0,
            'errors' => [['index' => 1, 'field' => 'status', 'message' => 'invalid']],
            'skipped' => 1,
            'skipped_orders' => [['index' => 3, 'external_order_id' => '104', 'reason' => 'older_than_retention']],
        ]);
        $this->jobManager->expects(self::once())->method('recordSent')->with([7 => 2, 8 => 1]);

        $this->createCron()->execute();
    }

    public function testTransportFailureReschedulesBatchNonTerminally(): void
    {
        $event = $this->createEvent(5);
        $this->stubClaims([Client::DOMAIN_CATALOG => [$event]]);
        $this->client->method('ingest')
            ->willThrowException(new EngineTransportException('engine down', 503));

        $this->queue->expects(self::once())->method('markFailedMany')
            ->with([$event], 'engine down');
        $this->queue->expects(self::never())->method('markSent');

        $this->createCron()->execute();
    }

    public function testWholeBatchRequestErrorIsTerminal(): void
    {
        $event = $this->createEvent(6);
        $this->stubClaims([Client::DOMAIN_BROWSE => [$event]]);
        $this->client->method('ingest')
            ->willThrowException(new EngineRequestException('bad wrapper', 400));

        $this->queue->expects(self::once())->method('markFailedMany')
            ->with([$event], 'bad wrapper', true);

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
        $this->queue->expects(self::never())->method('markFailedMany');
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
            $this->oneEngineConnection($settings, $this->client),
            $this->queue,
            $this->catalogIngest,
            new OptOutReplay($this->profilingConsent),
            new Json(),
            $this->createMock(Logger::class),
            $this->pace,
            $this->jobManager
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
        $this->queue->expects(self::never())->method('markFailedMany');

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
        $this->queue->expects(self::once())->method('markFailedMany')
            ->with([$keyless], 'catalog/remove row has no product_id', true);

        $this->createCron()->execute();
    }

    public function testCatalogRemoveTransportFailureReschedulesNonTerminally(): void
    {
        $event = $this->createEvent(41, Client::DOMAIN_CATALOG_REMOVE);
        $this->payloads = [41 => ['product_id' => '7']];
        $this->stubClaims([Client::DOMAIN_CATALOG_REMOVE => [$event]]);
        $this->client->method('catalogRemove')
            ->willThrowException(new EngineTransportException('engine down', 503));

        $this->queue->expects(self::once())->method('markFailedMany')
            ->with([$event], 'engine down');
        $this->queue->expects(self::never())->method('markSent');

        $this->createCron()->execute();
    }

    public function testCatalogRemoveRequestErrorIsTerminal(): void
    {
        // A 404 here means the engine predates §3b ("not yet available") —
        // parked, never retried automatically.
        $event = $this->createEvent(51, Client::DOMAIN_CATALOG_REMOVE);
        $this->payloads = [51 => ['product_id' => '7']];
        $this->stubClaims([Client::DOMAIN_CATALOG_REMOVE => [$event]]);
        $this->client->method('catalogRemove')
            ->willThrowException(new EngineRequestException('HTTP 404: not found', 404));

        $this->queue->expects(self::once())->method('markFailedMany')
            ->with([$event], 'HTTP 404: not found', true);

        $this->createCron()->execute();
    }

    /**
     * PRO-3760: the shoppers of the customers and orders the engine
     * confirmed — not of a row it refused, nor of a catalog row — have
     * their stored opt-out sent again, one call per batch.
     */
    public function testTheShoppersOfConfirmedCustomersAndOrdersHaveTheirOptOutsSentAgain(): void
    {
        $this->payloads = [
            21 => ['email' => 'u1@example.invalid'],
            22 => ['email' => 'u2@example.invalid'],
            23 => ['email' => 'u3@example.invalid'],
            31 => ['external_order_id' => '100', 'customer_email' => 'u4@example.invalid'],
        ];
        $this->stubClaims([
            Client::DOMAIN_CATALOG => [$this->createEvent(11)],
            Client::DOMAIN_CUSTOMERS => [
                $this->createEvent(21, Client::DOMAIN_CUSTOMERS),
                $this->createEvent(22, Client::DOMAIN_CUSTOMERS),
                $this->createEvent(23, Client::DOMAIN_CUSTOMERS),
            ],
            Client::DOMAIN_ORDERS => [$this->createEvent(31, Client::DOMAIN_ORDERS)],
        ]);
        $this->client->method('ingest')->willReturnCallback(
            static fn (string $domain): array => $domain === Client::DOMAIN_CUSTOMERS
                ? ['processed' => 1, 'deduplicated' => 1, 'errors' => [['index' => 1, 'field' => 'email']]]
                : ['processed' => 1, 'deduplicated' => 0, 'errors' => []]
        );

        $resent = [];
        $this->profilingConsent->method('resendOptOuts')->willReturnCallback(
            static function (array $emails) use (&$resent): void {
                $resent[] = $emails;
            }
        );

        $this->createCron()->execute();

        self::assertSame([['u1@example.invalid', 'u3@example.invalid'], ['u4@example.invalid']], $resent);
    }

    /**
     * CON-232: the run sends batch after batch, one domain after another in
     * turn, until every domain's queue is empty — not one batch a minute.
     */
    public function testTheRunSendsUntilTheQueueIsEmptyTakingTheDomainsInTurn(): void
    {
        $this->stubBatches([
            Client::DOMAIN_CATALOG => [[$this->createEvent(1)], [$this->createEvent(2)], [$this->createEvent(3)]],
            Client::DOMAIN_ORDERS => [
                [$this->createEvent(4, Client::DOMAIN_ORDERS)],
                [$this->createEvent(5, Client::DOMAIN_ORDERS)],
            ],
        ]);
        $sent = [];
        $this->client->method('ingest')->willReturnCallback(
            static function (string $domain) use (&$sent): array {
                $sent[] = $domain;

                return ['processed' => 1, 'deduplicated' => 0, 'errors' => []];
            }
        );
        $this->pace->expects(self::exactly(5))->method('awaitTurn');

        $this->createCron()->execute();

        self::assertSame(['catalog', 'orders', 'catalog', 'orders', 'catalog'], $sent);
        self::assertSame(
            [Client::DOMAIN_CATALOG => 4, Client::DOMAIN_ORDERS => 4],
            array_intersect_key(
                array_count_values($this->claims),
                [Client::DOMAIN_CATALOG => true, Client::DOMAIN_ORDERS => true]
            ),
            'Each domain is asked until a round finds nothing left'
        );
    }

    public function testTheRunStopsWhenItsBudgetIsSpent(): void
    {
        $this->pace = $this->createMock(IngestPace::class);
        $this->pace->method('hasTime')->willReturnOnConsecutiveCalls(true, true, false);
        $this->stubBatches([
            Client::DOMAIN_CATALOG => [[$this->createEvent(1)], [$this->createEvent(2)], [$this->createEvent(3)]],
        ]);
        $this->client->expects(self::once())->method('ingest')
            ->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);

        $this->createCron()->execute();

        // catalog sent, customers asked (empty), then the budget is spent.
        self::assertSame([Client::DOMAIN_CATALOG, Client::DOMAIN_CUSTOMERS], $this->claims);
    }

    /**
     * A 429 a retry got past still ends the run: the engine asked to slow
     * down, and the rest waits for the next run.
     */
    public function testTheRunStopsWhenTheEngineAsksToSlowDown(): void
    {
        $this->stubBatches([
            Client::DOMAIN_CATALOG => [[$this->createEvent(1)], [$this->createEvent(2)]],
            Client::DOMAIN_ORDERS => [[$this->createEvent(3, Client::DOMAIN_ORDERS)]],
        ]);
        $this->client->expects(self::once())->method('ingest')
            ->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);
        $this->client->method('wasThrottled')->willReturn(true);

        $this->queue->expects(self::once())->method('markSent');

        $this->createCron()->execute();

        self::assertSame([Client::DOMAIN_CATALOG], $this->claims);
    }

    /**
     * A batch that failed in transport (429 or 5xx after the retries, or no
     * answer) ends the run; the rows wait for their retry.
     */
    public function testATransportFailureEndsTheRun(): void
    {
        $this->stubBatches([
            Client::DOMAIN_CATALOG => [[$this->createEvent(1)], [$this->createEvent(2)]],
            Client::DOMAIN_ORDERS => [[$this->createEvent(3, Client::DOMAIN_ORDERS)]],
        ]);
        $this->client->expects(self::once())->method('ingest')
            ->willThrowException(new EngineTransportException('engine down', 503));

        $this->createCron()->execute();

        self::assertSame([Client::DOMAIN_CATALOG], $this->claims);
    }

    /**
     * A whole-batch 4xx ends its domain for the run, and the other domains
     * go on: a key the engine no longer takes fails one batch per domain a
     * run, not the whole queue.
     */
    public function testAWholeBatchRequestErrorEndsItsDomainForTheRun(): void
    {
        $this->stubBatches([
            Client::DOMAIN_CATALOG => [[$this->createEvent(1)], [$this->createEvent(2)]],
            Client::DOMAIN_ORDERS => [
                [$this->createEvent(3, Client::DOMAIN_ORDERS)],
                [$this->createEvent(4, Client::DOMAIN_ORDERS)],
            ],
        ]);
        $sent = [];
        $this->client->method('ingest')->willReturnCallback(
            static function (string $domain) use (&$sent): array {
                if ($domain === Client::DOMAIN_CATALOG) {
                    throw new EngineRequestException('HTTP 400: bad wrapper', 400);
                }
                $sent[] = $domain;

                return ['processed' => 1, 'deduplicated' => 0, 'errors' => []];
            }
        );

        $this->createCron()->execute();

        self::assertSame([Client::DOMAIN_ORDERS, Client::DOMAIN_ORDERS], $sent);
        self::assertSame(1, array_count_values($this->claims)[Client::DOMAIN_CATALOG]);
    }

    /**
     * CON-237: under one store the primary is the only connection, and the
     * run asks the queue for exactly what it asked before: each domain in
     * turn, then §3b, round after round — every claim the primary's.
     */
    public function testOneConnectionClaimsInTheOrderItAlwaysDid(): void
    {
        $this->stubBatches([
            Client::DOMAIN_CATALOG => [[$this->createEvent(1)]],
            Client::DOMAIN_CATALOG_REMOVE => [[$this->createEvent(2, Client::DOMAIN_CATALOG_REMOVE)]],
        ]);
        $this->payloads = [2 => ['product_id' => '7']];
        $this->client->method('ingest')->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);
        $this->client->method('catalogRemove')->willReturn(['ok' => true]);

        $this->createCron()->execute();

        $round = ['0:catalog', '0:customers', '0:orders', '0:browse', '0:catalog_remove'];
        self::assertSame(array_merge($round, $round), $this->connectionClaims);
    }

    /**
     * CON-237: every connection that may send takes its turn in each round
     * — one batch per domain of its own rows, then its §3b batch — with
     * its own client, until no connection has a row left.
     */
    public function testEachConnectionTakesItsTurnWithItsOwnClient(): void
    {
        $other = $this->createMock(Client::class);
        $this->stubBatches([
            '0:catalog' => [[$this->createEvent(1)], [$this->createEvent(2)]],
            '2:catalog' => [[$this->createEvent(3)]],
            '2:orders' => [[$this->createEvent(4, Client::DOMAIN_ORDERS)]],
        ]);
        $sent = [];
        $this->client->method('ingest')->willReturnCallback(
            static function (string $domain) use (&$sent): array {
                $sent[] = 'primary:' . $domain;

                return ['processed' => 1, 'deduplicated' => 0, 'errors' => []];
            }
        );
        $other->method('ingest')->willReturnCallback(
            static function (string $domain) use (&$sent): array {
                $sent[] = 'website:' . $domain;

                return ['processed' => 1, 'deduplicated' => 0, 'errors' => []];
            }
        );

        $this->createCron(null, $this->twoConnections($other))->execute();

        self::assertSame(['primary:catalog', 'website:catalog', 'website:orders', 'primary:catalog'], $sent);
        self::assertNotContains('-:catalog', $this->connectionClaims, 'Every claim names its connection');
    }

    /**
     * CON-237: a 429 a retry got past stops the connection that met it;
     * the other connection sends on.
     */
    public function testAThrottledConnectionStopsAloneWhileTheOtherSendsOn(): void
    {
        $other = $this->createMock(Client::class);
        $this->stubBatches([
            '0:catalog' => [[$this->createEvent(1)], [$this->createEvent(2)]],
            '2:catalog' => [[$this->createEvent(3)], [$this->createEvent(4)]],
        ]);
        $this->client->expects(self::once())->method('ingest')
            ->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);
        $this->client->method('wasThrottled')->willReturn(true);
        $other->expects(self::exactly(2))->method('ingest')
            ->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);

        $this->createCron(null, $this->twoConnections($other))->execute();

        self::assertSame(['0:catalog'], array_values(array_filter(
            $this->connectionClaims,
            static fn (string $claim): bool => str_starts_with($claim, '0:')
        )), 'The primary asks for nothing more once it is throttled');
    }

    /**
     * CON-237: a batch that failed in transport stops its connection for
     * the run; the other connection sends on.
     */
    public function testATransportFailureStopsOnlyItsConnection(): void
    {
        $other = $this->createMock(Client::class);
        $this->stubBatches([
            '0:catalog' => [[$this->createEvent(1)]],
            '0:orders' => [[$this->createEvent(2, Client::DOMAIN_ORDERS)]],
            '2:catalog' => [[$this->createEvent(3)]],
            '2:orders' => [[$this->createEvent(4, Client::DOMAIN_ORDERS)]],
        ]);
        $this->client->expects(self::exactly(2))->method('ingest')
            ->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);
        $other->expects(self::once())->method('ingest')
            ->willThrowException(new EngineTransportException('engine down', 503));

        $this->createCron(null, $this->twoConnections($other))->execute();

        self::assertSame(['2:catalog'], array_values(array_filter(
            $this->connectionClaims,
            static fn (string $claim): bool => str_starts_with($claim, '2:')
        )));
    }

    /**
     * CON-237: a whole-batch 4xx ends that connection's domain for the
     * run, not the other connection's.
     */
    public function testAWholeBatchRequestErrorEndsTheDomainOfItsConnectionOnly(): void
    {
        $other = $this->createMock(Client::class);
        $this->stubBatches([
            '0:catalog' => [[$this->createEvent(1)], [$this->createEvent(2)]],
            '2:catalog' => [[$this->createEvent(3)], [$this->createEvent(4)]],
        ]);
        $this->client->expects(self::once())->method('ingest')
            ->willThrowException(new EngineRequestException('HTTP 400: bad wrapper', 400));
        $other->expects(self::exactly(2))->method('ingest')
            ->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);

        $this->createCron(null, $this->twoConnections($other))->execute();
    }

    /**
     * CON-237: a connection whose engine store refused it is left out of
     * the run; the other connection sends. One refused mid-run releases
     * its batch and stops; the other goes on.
     */
    public function testARefusedConnectionIsSkippedWhileTheOtherSends(): void
    {
        $refused = $this->createMock(Settings::class);
        $refused->method('isSendingAllowed')->willReturn(false);
        $refused->method('isRefused')->willReturn(true);
        $other = $this->createMock(Client::class);
        $this->stubBatches([
            '0:catalog' => [[$this->createEvent(1)]],
            '2:catalog' => [[$this->createEvent(3)]],
        ]);
        $this->client->expects(self::never())->method('ingest');
        $other->expects(self::once())->method('ingest')
            ->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);

        $this->createCron(null, $this->twoConnections($other, $refused))->execute();

        self::assertSame([], array_values(array_filter(
            $this->connectionClaims,
            static fn (string $claim): bool => str_starts_with($claim, '0:')
        )), 'Nothing of the refused connection is claimed');
    }

    public function testAConnectionRefusedMidRunReleasesItsBatchAndStopsAlone(): void
    {
        $account = new class {
            public bool $refused = false;
        };
        $website = $this->createMock(Settings::class);
        $website->method('isSendingAllowed')->willReturn(true);
        $website->method('isRefused')->willReturnCallback(static fn (): bool => $account->refused);
        $other = $this->createMock(Client::class);
        $refusedBatch = [$this->createEvent(3)];
        $this->stubBatches([
            '0:catalog' => [[$this->createEvent(1)], [$this->createEvent(2)]],
            '2:catalog' => [$refusedBatch, [$this->createEvent(4)]],
        ]);
        $other->expects(self::once())->method('ingest')->willReturnCallback(
            static function () use ($account): array {
                $account->refused = true;

                throw new EngineRequestException('HTTP 403: tenant_inactive', 403);
            }
        );
        $this->client->expects(self::exactly(2))->method('ingest')
            ->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);
        $this->queue->expects(self::once())->method('release')->with($refusedBatch);

        $connections = $this->engineConnections([
            [$this->engineConnection(0, $this->settings, [1], [1], 1), $this->client],
            [$this->engineConnection(2, $website, [2], [2, 3], 2), $other],
        ]);
        $this->createCron(null, $connections)->execute();
    }

    /**
     * CON-237: the stored opt-outs of the shoppers a connection's engine
     * store confirmed go to that connection.
     */
    public function testTheOptOutsGoToTheConnectionThatConfirmedTheShoppers(): void
    {
        $other = $this->createMock(Client::class);
        $this->payloads = [
            21 => ['email' => 'primary@example.invalid'],
            22 => ['email' => 'website@example.invalid'],
        ];
        $this->stubBatches([
            '0:customers' => [[$this->createEvent(21, Client::DOMAIN_CUSTOMERS)]],
            '2:customers' => [[$this->createEvent(22, Client::DOMAIN_CUSTOMERS)]],
        ]);
        $this->client->method('ingest')->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);
        $other->method('ingest')->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);
        $resent = [];
        $this->profilingConsent->method('resendOptOuts')->willReturnCallback(
            static function (array $emails, int $websiteId) use (&$resent): void {
                $resent[] = [$emails, $websiteId];
            }
        );

        $this->createCron(null, $this->twoConnections($other))->execute();

        self::assertSame([[['primary@example.invalid'], 0], [['website@example.invalid'], 2]], $resent);
    }

    /**
     * The primary (website 1, store view 1) with $this->client, and the
     * connection of website 2 (store views 2 and 3) with its own client.
     */
    private function twoConnections(Client $other, ?Settings $primarySettings = null): Connections
    {
        $website = $this->createMock(Settings::class);
        $website->method('isSendingAllowed')->willReturn(true);
        $website->method('isRefused')->willReturn(false);

        return $this->engineConnections([
            [$this->engineConnection(0, $primarySettings ?? $this->settings, [1], [1], 1), $this->client],
            [$this->engineConnection(2, $website, [2], [2, 3], 2), $other],
        ]);
    }

    /**
     * One batch per domain, handed out once, as the queue does.
     *
     * @param array<string, IngestEvent[]> $byDomain
     */
    private function stubClaims(array $byDomain): void
    {
        $this->stubBatches(array_map(static fn (array $batch): array => [$batch], $byDomain));
    }

    /**
     * Batches per domain, each handed out once and in order, as the queue
     * does; the domains' claims are recorded in $this->claims. A key
     * "<connection key>:<domain>" holds one connection's batches; a plain
     * domain, the primary's.
     *
     * @param array<string, IngestEvent[][]> $batchesByDomain
     */
    private function stubBatches(array $batchesByDomain): void
    {
        $this->queue->method('claimBatch')->willReturnCallback(
            function (string $domain, int $limit, ?Connection $connection = null) use (&$batchesByDomain): array {
                $this->claims[] = $domain;
                $key = $connection === null ? '-' : (string)$connection->key();
                $this->connectionClaims[] = $key . ':' . $domain;
                $batchKey = isset($batchesByDomain[$key . ':' . $domain]) ? $key . ':' . $domain : $domain;
                if ($key !== '0' && $batchKey === $domain) {
                    return [];
                }
                if (empty($batchesByDomain[$batchKey])) {
                    return [];
                }

                return array_shift($batchesByDomain[$batchKey]);
            }
        );
    }

    private function createCron(?Logger $logger = null, ?Connections $connections = null): FlushIngestQueue
    {
        return new FlushIngestQueue(
            $connections ?? $this->oneEngineConnection($this->settings, $this->client),
            $this->queue,
            $this->catalogIngest,
            new OptOutReplay($this->profilingConsent),
            new Json(),
            $logger ?? $this->createMock(Logger::class),
            $this->pace,
            $this->jobManager
        );
    }

    private function createEvent(int $id, string $domain = 'catalog', ?int $jobId = null): IngestEvent&MockObject
    {
        $event = $this->createMock(IngestEvent::class);
        $event->method('getId')->willReturn($id);
        $event->method('getDomain')->willReturn($domain);
        $event->method('getData')->willReturnCallback(
            static fn (string $key = '') => $key === 'job_id' ? $jobId : null
        );

        return $event;
    }
}
