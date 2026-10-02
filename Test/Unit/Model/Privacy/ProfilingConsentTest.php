<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Privacy;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\Model\Privacy\ProfilingOptOuts;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;

class ProfilingConsentTest extends TestCase
{
    private const NOW = 1790000000;
    private const NOW_Z = '2026-09-21T14:13:20Z';

    /** @var array<int, array{event_type: string, payload: array<string, mixed>, entity_id: ?string}> */
    private array $enqueued = [];

    /** @var array<string, int> moment by address */
    private array $records = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    private array $smailyWrites = [];

    /** @var array<string, string> */
    private array $cache = [];

    private SmailyClient&MockObject $smailyClient;
    private Settings&MockObject $engineSettings;

    protected function setUp(): void
    {
        $this->enqueued = [];
        $this->records = [];
        $this->smailyWrites = [];
        $this->cache = [];

        $this->smailyClient = $this->createMock(SmailyClient::class);
        $this->smailyClient->method('post')->willReturnCallback(
            function (string $endpoint, array $body): array {
                $this->smailyWrites[] = $body;

                return ['code' => 101];
            }
        );
        $this->engineSettings = $this->createMock(Settings::class);
        $this->engineSettings->method('isConnected')->willReturn(true);
    }

    public function testAnOptOutIsQueuedForTheEngineWithItsMoment(): void
    {
        $this->consent()->setAllowed('Person@Example.com', false, 1);

        self::assertSame([[
            'event_type' => EventType::ENGINE_PROFILING_CONSENT,
            'payload' => ['email' => 'person@example.com', 'opt_out' => true, 'opted_out_at' => self::NOW_Z],
            'entity_id' => 'person@example.com',
        ]], $this->enqueued);
    }

    public function testAnOptOutIsKeptLocallyWithItsMoment(): void
    {
        $this->consent()->setAllowed('person@example.com', false, 1);

        self::assertSame(['person@example.com' => self::NOW], $this->records);
    }

    public function testAnOptInClearsTheLocalOptOutAndIsQueuedForTheEngine(): void
    {
        $this->records['person@example.com'] = self::NOW - 3600;

        $this->consent()->setAllowed('person@example.com', true, 1);

        self::assertSame([], $this->records);
        self::assertSame(
            ['email' => 'person@example.com', 'opt_out' => false],
            $this->enqueued[0]['payload']
        );
    }

    public function testTheChoiceStillGoesToTheSmailyContact(): void
    {
        $this->consent()->setAllowed('person@example.com', false, 1);

        self::assertSame([[[
            'email' => 'person@example.com',
            'smaily_rec_profiling' => 0,
            'smaily_rec_profiling_ts' => self::NOW_Z,
        ]]], $this->smailyWrites);
    }

    public function testAFailedSmailyWriteStillQueuesTheEngineAndKeepsTheChoice(): void
    {
        $this->smailyClient = $this->createMock(SmailyClient::class);
        $this->smailyClient->method('post')->willThrowException(new SmailyClientException('Smaily is down'));

        $this->consent()->setAllowed('person@example.com', false, 1);

        self::assertCount(1, $this->enqueued);
        self::assertSame(['person@example.com' => self::NOW], $this->records);
        self::assertSame('0', $this->cache[$this->cacheKey('person@example.com')]);
    }

    public function testNothingIsQueuedWithoutCampaignIntelligence(): void
    {
        $this->engineSettings = $this->createMock(Settings::class);
        $this->engineSettings->method('isConnected')->willReturn(false);

        $this->consent()->setAllowed('person@example.com', false, 1);

        self::assertSame([], $this->enqueued);
        self::assertSame(['person@example.com' => self::NOW], $this->records, 'The choice is kept all the same');
    }

    private function consent(): ProfilingConsent
    {
        $provider = $this->createMock(SmailyClientProvider::class);
        $provider->method('forStore')->willReturn($this->smailyClient);

        $eventQueue = $this->createMock(EventQueue::class);
        $eventQueue->method('enqueue')->willReturnCallback(
            function (string $eventType, array $payload, ?string $entityId = null): bool {
                $this->enqueued[] = ['event_type' => $eventType, 'payload' => $payload, 'entity_id' => $entityId];

                return true;
            }
        );

        $optOuts = $this->createMock(ProfilingOptOuts::class);
        $optOuts->method('moment')->willReturnCallback(
            fn (string $email): ?int => $this->records[$email] ?? null
        );
        $optOuts->method('record')->willReturnCallback(function (string $email, int $moment): void {
            $this->records[$email] = $moment;
        });
        $optOuts->method('forget')->willReturnCallback(function (string $email): void {
            unset($this->records[$email]);
        });

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key): string|false => $this->cache[$key] ?? false);
        $cache->method('save')->willReturnCallback(function (string $data, string $key): bool {
            $this->cache[$key] = $data;

            return true;
        });

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);

        return new ProfilingConsent(
            $provider,
            $this->engineSettings,
            $eventQueue,
            $optOuts,
            $cache,
            $dateTime,
            $this->createMock(Logger::class)
        );
    }

    private function cacheKey(string $email): string
    {
        return 'smaily_profiling_' . sha1($email);
    }
}
