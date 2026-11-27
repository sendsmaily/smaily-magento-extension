<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Privacy;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\RequestInterface;
use Smaily\Connect\Cron\FlushEventQueue;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\PrivacyRequestBudget;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\SleeperInterface;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\ProfilingOptOuts;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\Queue\Handler\ProfilingConsentHandler;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\Report\ConnectionHealth;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Model\StorefrontScript;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * CON-432: the shoppers' profiling choices go to the engine at the pace of
 * its limit on privacy requests, 10 in 60 seconds per engine store
 * (contract v1.37.1). Drained by the real flush cron, handler and engine
 * client against a real database; only the engine's HTTP answers and the
 * application cache are stand-ins.
 *
 * Contacts are synthetic throughout.
 */
class PrivacyRequestPacingTest extends IntegrationTestCase
{
    use EngineConnectionsStub;

    /** @var array<int, string> the paths the engine was asked, in order */
    private array $requests = [];

    /** @var int[] the waits inside a request */
    private array $sleeps = [];

    /** How many requests the engine still answers 429. */
    private int $throttledLeft = 0;

    /** @var array<string, string> */
    private array $cache = [];

    private Settings&MockObject $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isConnected')->willReturn(true);
        $this->settings->method('getApiKey')->willReturn('sk_test_key');
        $this->settings->method('getTenantId')->willReturn('6d1f0c3e-2a7b-4c59-8e14-b9a0f3d5c217');
        $this->settings->method('getEndpoint')->willReturnCallback(
            static fn (string $key): ?string => $key === 'customer_opt_out'
                ? 'https://engine.test/api/v1/customer/{email}/opt-out'
                : null
        );
    }

    public function testARunSendsTenChoicesAndLeavesTheRestForTheNextMinuteWithNoAttemptSpent(): void
    {
        $this->enqueueChoices(12);

        $this->runCron();

        self::assertCount(PrivacyRequestBudget::LIMIT, $this->requests);
        $rows = $this->fetchAll(EventResource::TABLE_NAME);
        self::assertSame(
            array_merge(array_fill(0, 10, Event::STATUS_SENT), [Event::STATUS_PENDING, Event::STATUS_PENDING]),
            array_column($rows, 'status')
        );
        foreach ([$rows[10], $rows[11]] as $row) {
            self::assertSame('0', (string)$row['attempts'], 'Waiting for the limit spends no attempt');
            self::assertNull($row['last_error']);
            self::assertNull($row['claim_token']);
            self::assertSame($this->clockDate(PrivacyRequestBudget::WINDOW_SECONDS), $row['next_retry_at']);
        }

        // Due again only once the minute is over: a run before sends nothing.
        $this->clock->travel(PrivacyRequestBudget::WINDOW_SECONDS - 1);
        $this->runCron();
        self::assertCount(PrivacyRequestBudget::LIMIT, $this->requests);

        $this->clock->travel(1);
        $this->runCron();
        self::assertCount(12, $this->requests);
        self::assertSame(
            array_fill(0, 12, Event::STATUS_SENT),
            array_column($this->fetchAll(EventResource::TABLE_NAME), 'status')
        );
        self::assertSame([], $this->sleeps);
    }

    public function testA429DelaysTheRowsAsLongAsTheEngineAsksWithoutWaitingInTheRun(): void
    {
        $this->throttledLeft = 1;
        $this->enqueueChoices(3);

        $this->runCron();

        self::assertCount(1, $this->requests, 'After the 429 the run sends no more privacy requests');
        self::assertSame([], $this->sleeps, 'Nothing waits inside the run');
        foreach ($this->fetchAll(EventResource::TABLE_NAME) as $row) {
            self::assertSame(Event::STATUS_PENDING, $row['status']);
            self::assertSame('0', (string)$row['attempts']);
            self::assertNull($row['last_error']);
            self::assertSame($this->clockDate(120), $row['next_retry_at']);
        }

        $this->clock->travel(120);
        $this->runCron();
        self::assertSame(
            [Event::STATUS_SENT, Event::STATUS_SENT, Event::STATUS_SENT],
            array_column($this->fetchAll(EventResource::TABLE_NAME), 'status')
        );
    }

    /**
     * However long the engine keeps answering 429, a choice is never parked
     * as failed: the retry ladder's five attempts stay unspent.
     */
    public function testA429NeverUsesUpTheRetryLadder(): void
    {
        $this->throttledLeft = 2 * EventQueue::MAX_ATTEMPTS;
        $this->enqueueChoices(1);

        for ($run = 0; $run < 2 * EventQueue::MAX_ATTEMPTS; $run++) {
            $this->runCron();
            $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
            self::assertSame(Event::STATUS_PENDING, $row['status']);
            self::assertSame('0', (string)$row['attempts']);
            $this->clock->travel(120);
        }

        $this->runCron();
        self::assertSame(Event::STATUS_SENT, $this->fetchAll(EventResource::TABLE_NAME)[0]['status']);
        self::assertCount(2 * EventQueue::MAX_ATTEMPTS + 1, $this->requests);
    }

    private function enqueueChoices(int $count): void
    {
        /** @var EventQueue $queue */
        $queue = $this->objectManager->get(EventQueue::class);
        for ($i = 1; $i <= $count; $i++) {
            // An opt-in: the store holds no opt-out, so the choice is current.
            $queue->enqueue(EventType::ENGINE_PROFILING_CONSENT, [
                'email' => sprintf('shopper-%d@example.com', $i),
                'opt_out' => false,
            ]);
        }
    }

    private function runCron(): void
    {
        $optOuts = $this->createMock(ProfilingOptOuts::class);
        $optOuts->method('moment')->willReturn(null);

        /** @var ProfilingConsentHandler $handler */
        $handler = $this->objectManager->create(ProfilingConsentHandler::class, [
            'connections' => $this->oneEngineConnection($this->settings, $this->client()),
            'optOuts' => $optOuts,
        ]);
        /** @var FlushEventQueue $cron */
        $cron = $this->objectManager->create(FlushEventQueue::class, [
            'handlerPool' => new HandlerPool([EventType::ENGINE_PROFILING_CONSENT => $handler]),
        ]);
        $cron->execute();
    }

    /**
     * The real engine client, its HTTP answered by the mock engine and its
     * privacy budget kept in this test's cache on the harness clock.
     */
    private function client(): Client
    {
        $httpClientFactory = $this->createMock(HttpClientFactory::class);
        $httpClientFactory->method('create')->willReturnCallback(
            // The default stack, so a 429 throws as it does against the engine.
            fn (array $config = []): HttpClient => new HttpClient(
                ['handler' => HandlerStack::create($this->mockEngine())] + $config
            )
        );
        $sleeper = $this->createMock(SleeperInterface::class);
        $sleeper->method('sleep')->willReturnCallback(function (int $seconds): void {
            $this->sleeps[] = $seconds;
        });
        $storeManager = $this->createMock(StoreManagerInterface::class);

        /** @var PrivacyRequestBudget $budget */
        $budget = $this->objectManager->create(PrivacyRequestBudget::class, ['cache' => $this->cache()]);

        return new Client(
            $this->settings,
            $httpClientFactory,
            $this->createMock(ProductMetadataInterface::class),
            $storeManager,
            $this->createMock(Logger::class),
            $sleeper,
            new StorefrontScript($storeManager, $this->createMock(Http::class)),
            $this->createMock(ConnectionHealth::class),
            $budget
        );
    }

    private function mockEngine(): callable
    {
        return function (RequestInterface $request) {
            $this->requests[] = $request->getUri()->getPath();
            if ($this->throttledLeft > 0) {
                $this->throttledLeft--;

                return Create::promiseFor(new Response(
                    429,
                    ['Content-Type' => 'application/json'],
                    '{"error":"rate_limit_exceeded","retry_after_seconds":120}'
                ));
            }

            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'));
        };
    }

    /**
     * The application cache, kept between the cron runs as Magento's is.
     */
    private function cache(): CacheInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key): string|false => $this->cache[$key] ?? false);
        $cache->method('save')->willReturnCallback(function (string $data, string $key): bool {
            $this->cache[$key] = $data;

            return true;
        });

        return $cache;
    }
}
