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
use Smaily\Connect\Model\Client\Exception\ApiException;
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

    /** @var array<string, string>|\Throwable what Smaily answers for the contact */
    private array|\Throwable $contact = [];

    private SmailyClient&MockObject $smailyClient;
    private Settings&MockObject $engineSettings;

    protected function setUp(): void
    {
        $this->smailyClient = $this->createMock(SmailyClient::class);
        $this->smailyClient->method('get')->willReturnCallback(function (): array {
            if ($this->contact instanceof \Throwable) {
                throw $this->contact;
            }

            return $this->contact;
        });
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

    public function testAContactWhoUnsubscribedInSmailyIsNotProfiled(): void
    {
        $this->contact = ['email' => 'person@example.com', 'is_unsubscribed' => '1', 'smaily_rec_profiling' => '1'];

        self::assertFalse($this->consent()->isAllowed('person@example.com', 1));
    }

    public function testASubscribedContactWithoutAPreferenceIsProfiled(): void
    {
        $this->contact = ['email' => 'person@example.com', 'is_unsubscribed' => '0'];

        self::assertTrue($this->consent()->isAllowed('person@example.com', 1));
    }

    public function testAnUnsubscribeInTheStoreStopsProfiling(): void
    {
        $consent = $this->consent();
        $consent->optOutOnUnsubscribe('Person@Example.com');

        self::assertSame(['person@example.com' => self::NOW], $this->records);
        self::assertSame(
            ['email' => 'person@example.com', 'opt_out' => true, 'opted_out_at' => self::NOW_Z],
            $this->enqueued[0]['payload']
        );
        self::assertFalse($consent->isAllowed('person@example.com', 1));
        self::assertSame([], $this->smailyWrites, 'The unsubscribe itself tells Smaily; no profiling field is written');
    }

    public function testAnOlderOptInOnTheContactDoesNotLiftANewerStoreOptOut(): void
    {
        $this->records['person@example.com'] = self::NOW - 3600;
        $this->contact = ['email' => 'person@example.com', 'is_unsubscribed' => '0',
            'smaily_rec_profiling' => '1', 'smaily_rec_profiling_ts' => gmdate('Y-m-d\TH:i:s\Z', self::NOW - 7200)];

        self::assertFalse($this->consent()->isAllowed('person@example.com', 1));
        self::assertSame([[[
            'email' => 'person@example.com',
            'smaily_rec_profiling' => 0,
            'smaily_rec_profiling_ts' => self::NOW_Z,
        ]]], $this->smailyWrites, 'The newest choice is written back to the contact');
        self::assertSame(['person@example.com' => self::NOW], $this->records, 'Its moment is the one Smaily now holds');
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function untrustworthyTimestamps(): array
    {
        return [
            'none' => [null],
            'not the Z form' => ['2026-09-21 15:13:20'],
            'an offset' => ['2026-09-21T15:13:20+00:00'],
            'a relative word' => ['tomorrow'],
            'rolled over' => ['2026-02-30T10:00:00Z'],
            'in the future' => [gmdate('Y-m-d\TH:i:s\Z', self::NOW + 301)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('untrustworthyTimestamps')]
    public function testAnOptInWithoutATrustworthyTimestampCountsAsOlder(?string $timestamp): void
    {
        $this->records['person@example.com'] = self::NOW - 3600;
        $this->contact = ['email' => 'person@example.com', 'is_unsubscribed' => '0', 'smaily_rec_profiling' => '1'];
        if ($timestamp !== null) {
            $this->contact['smaily_rec_profiling_ts'] = $timestamp;
        }

        self::assertFalse($this->consent()->isAllowed('person@example.com', 1));
    }

    public function testANewerOptInOnTheContactLiftsTheStoreOptOut(): void
    {
        $this->records['person@example.com'] = self::NOW - 3600;
        $this->contact = ['email' => 'person@example.com', 'is_unsubscribed' => '0',
            'smaily_rec_profiling' => '1', 'smaily_rec_profiling_ts' => gmdate('Y-m-d\TH:i:s\Z', self::NOW - 60)];

        self::assertTrue($this->consent()->isAllowed('person@example.com', 1));
        self::assertSame([], $this->records);
        self::assertSame(
            ['email' => 'person@example.com', 'opt_out' => false],
            $this->enqueued[0]['payload'],
            'The engine hears the newest choice too'
        );
        self::assertSame([], $this->smailyWrites);
    }

    public function testAContactThatNeverHeardTheOptOutKeepsTheShopperOptedOut(): void
    {
        $this->records['person@example.com'] = self::NOW - 3600;
        $this->contact = ['email' => 'person@example.com', 'is_unsubscribed' => '0'];

        self::assertFalse($this->consent()->isAllowed('person@example.com', 1));
        self::assertCount(1, $this->smailyWrites, 'The opt-out is carried to the contact');
    }

    public function testAnUnknownContactIsNotCreatedToHoldTheOptOut(): void
    {
        $this->records['person@example.com'] = self::NOW - 3600;
        $this->contact = new ApiException('Contact not found', ApiException::CODE_EMAIL_NOT_FOUND);

        self::assertFalse($this->consent()->isAllowed('person@example.com', 1));
        self::assertSame([], $this->smailyWrites);
        self::assertSame(['person@example.com' => self::NOW - 3600], $this->records);
    }

    public function testWhenSmailyCannotBeReadTheStoreRecordDecides(): void
    {
        $this->contact = new SmailyClientException('Smaily is down');
        $this->records['person@example.com'] = self::NOW - 3600;

        self::assertFalse($this->consent()->isAllowed('person@example.com', 1));
    }

    public function testWhenSmailyCannotBeReadAShopperWithoutAnOptOutIsProfiled(): void
    {
        $this->contact = new SmailyClientException('Smaily is down');

        self::assertTrue($this->consent()->isAllowed('person@example.com', 1));
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function smailySideOptOuts(): array
    {
        return [
            'profiling opt-out' => [['email' => 'person@example.com', 'is_unsubscribed' => '0',
                'smaily_rec_profiling' => '0']],
            'unsubscribe' => [['email' => 'person@example.com', 'is_unsubscribed' => '1']],
        ];
    }

    /**
     * @param array<string, string> $contact
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('smailySideOptOuts')]
    public function testAnOptOutMadeInSmailyReachesTheEngine(array $contact): void
    {
        $this->contact = $contact;

        self::assertFalse($this->consent()->isAllowed('person@example.com', 1));
        self::assertSame(['person@example.com' => 0], $this->records, 'Kept as a mirror: any dated opt-in is newer');
        self::assertSame([[
            'event_type' => EventType::ENGINE_PROFILING_CONSENT,
            'payload' => ['email' => 'person@example.com', 'opt_out' => true, 'opted_out_at' => self::NOW_Z],
            'entity_id' => 'person@example.com',
        ]], $this->enqueued);
        self::assertSame([], $this->smailyWrites);
    }

    public function testAnOptOutTheStoreAlreadyHoldsIsNotQueuedAgain(): void
    {
        $this->records['person@example.com'] = self::NOW - 3600;
        $this->contact = ['email' => 'person@example.com', 'is_unsubscribed' => '0', 'smaily_rec_profiling' => '0'];

        self::assertFalse($this->consent()->isAllowed('person@example.com', 1));
        self::assertSame([], $this->enqueued);
        self::assertSame(['person@example.com' => self::NOW - 3600], $this->records);
    }

    /**
     * PRO-3591: My Account shows a preference only when the store knows it.
     * A shopper the store holds no opt-out for, whose contact Smaily could not
     * read, has no known preference — although the gate still profiles them
     * (fail open).
     */
    public function testAPreferenceSmailyCouldNotReadIsNotKnown(): void
    {
        $this->contact = new SmailyClientException('Smaily is down');
        $consent = $this->consent();

        self::assertNull($consent->knownPreference('person@example.com', 1));
        self::assertTrue($consent->isAllowed('person@example.com', 1));
    }

    public function testTheStoreRecordIsKnownWhenSmailyCannotBeRead(): void
    {
        $this->contact = new SmailyClientException('Smaily is down');
        $this->records['person@example.com'] = self::NOW - 3600;

        self::assertFalse($this->consent()->knownPreference('person@example.com', 1));
    }

    /**
     * @return array<string, array{0: array<string, string>|\Throwable, 1: bool}>
     */
    public static function successfulReads(): array
    {
        return [
            'opted in' => [['email' => 'person@example.com', 'is_unsubscribed' => '0',
                'smaily_rec_profiling' => '1'], true],
            'opted out' => [['email' => 'person@example.com', 'is_unsubscribed' => '0',
                'smaily_rec_profiling' => '0'], false],
            'no answer yet' => [['email' => 'person@example.com', 'is_unsubscribed' => '0'], true],
            'not a Smaily contact' => [
                new ApiException('Contact not found', ApiException::CODE_EMAIL_NOT_FOUND),
                true,
            ],
        ];
    }

    /**
     * @param array<string, string>|\Throwable $contact
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('successfulReads')]
    public function testAPreferenceSmailyAnsweredIsKnown(array|\Throwable $contact, bool $expected): void
    {
        $this->contact = $contact;

        self::assertSame($expected, $this->consent()->knownPreference('person@example.com', 1));
    }

    public function testAGuessIsCheckedWithSmailyAgainOnTheNextVisit(): void
    {
        $consent = $this->consent();
        $this->contact = new SmailyClientException('Smaily is down');
        self::assertNull($consent->knownPreference('person@example.com', 1));

        $this->contact = ['email' => 'person@example.com', 'is_unsubscribed' => '0', 'smaily_rec_profiling' => '0'];

        self::assertFalse($consent->knownPreference('person@example.com', 1));
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
        $cache->method('remove')->willReturnCallback(function (string $key): bool {
            unset($this->cache[$key]);

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
