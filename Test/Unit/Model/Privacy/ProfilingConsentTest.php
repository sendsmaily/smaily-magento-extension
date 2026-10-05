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

    /** @var array<string, bool> whether the record came only from unsubscribing, by address */
    private array $byUnsubscribe = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    private array $smailyWrites = [];

    private int $smailyReads = 0;

    /** @var string[] addresses with a profiling opt-out already waiting in the queue */
    private array $waitingOptOuts = [];

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
            $this->smailyReads++;
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

    /**
     * PRO-3619: Smaily creates a contact sent without a status as
     * subscribed, so a choice is written only to a contact Smaily has.
     *
     * @return array<string, array{0: bool}>
     */
    public static function choices(): array
    {
        return ['opt-out' => [false], 'opt-in' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('choices')]
    public function testAChoiceIsNotWrittenToAContactSmailyDoesNotHave(bool $allowed): void
    {
        $this->contact = new ApiException('Contact not found', ApiException::CODE_EMAIL_NOT_FOUND);

        $this->consent()->setAllowed('person@example.com', $allowed, 1);

        self::assertSame([], $this->smailyWrites, 'A write would create the contact as a subscriber');
        self::assertCount(1, $this->enqueued, 'The engine still hears the choice');
        self::assertSame($allowed ? [] : ['person@example.com' => self::NOW], $this->records);
    }

    public function testAChoiceIsNotWrittenWhenSmailyCannotSayWhetherItHasTheContact(): void
    {
        $this->contact = new SmailyClientException('Smaily is down');

        $this->consent()->setAllowed('person@example.com', false, 1);

        self::assertSame([], $this->smailyWrites);
        self::assertCount(1, $this->enqueued);
        self::assertSame(['person@example.com' => self::NOW], $this->records);
    }

    /**
     * @return array<string, array{0: array<string, string>|\Throwable, 1: int}>
     */
    public static function pageViewReads(): array
    {
        return [
            'a Smaily contact' => [['email' => 'person@example.com', 'is_unsubscribed' => '0'], 1],
            'not a Smaily contact' => [
                new ApiException('Contact not found', ApiException::CODE_EMAIL_NOT_FOUND),
                0,
            ],
        ];
    }

    /**
     * @param array<string, string>|\Throwable $contact
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pageViewReads')]
    public function testRecordingAChoiceReusesTheReadOfThePageView(array|\Throwable $contact, int $writes): void
    {
        $this->contact = $contact;
        $consent = $this->consent();
        $consent->knownPreference('person@example.com', 1);

        $consent->setAllowed('person@example.com', false, 1);
        $consent->setAllowed('person@example.com', true, 1);

        self::assertSame(1, $this->smailyReads, 'Smaily is read once, by the page view');
        self::assertCount(2 * $writes, $this->smailyWrites);
    }

    /**
     * PRO-3575: the cache keys carry the opt-out record's keyed hash of the
     * address, never a plain hash anyone can compute from an address.
     */
    public function testTheCacheKeysCarryTheKeyedHashOfTheAddress(): void
    {
        $this->contact = ['email' => 'person@example.com', 'is_unsubscribed' => '0'];
        $consent = $this->consent();
        $consent->isAllowed('person@example.com', 1);
        $consent->setAllowed('person@example.com', false, 1);

        self::assertEqualsCanonicalizing(
            [
                'smaily_profiling_' . self::keyedHash('person@example.com'),
                'smaily_profiling_contact_' . self::keyedHash('person@example.com'),
            ],
            array_keys($this->cache)
        );
        foreach (array_keys($this->cache) as $key) {
            self::assertStringNotContainsString(sha1('person@example.com'), $key);
        }
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
     * PRO-3594: subscribing again switches profiling back on when the opt-out
     * came only from unsubscribing — not when the shopper opted out of
     * profiling on their own.
     */
    public function testAnUnsubscribeIsKeptAsAnOptOutByUnsubscribing(): void
    {
        $this->consent()->optOutOnUnsubscribe('person@example.com');

        self::assertSame(['person@example.com' => true], $this->byUnsubscribe);
    }

    public function testSubscribingAgainSwitchesProfilingBackOnAfterAnUnsubscribe(): void
    {
        $consent = $this->consent();
        $consent->optOutOnUnsubscribe('person@example.com');
        $this->enqueued = [];
        // Smaily may not have heard the resubscribe yet.
        $this->contact = ['email' => 'person@example.com', 'is_unsubscribed' => '1'];

        $consent->optInOnResubscribe('Person@Example.com');

        self::assertSame([], $this->records);
        self::assertSame([[
            'event_type' => EventType::ENGINE_PROFILING_CONSENT,
            'payload' => ['email' => 'person@example.com', 'opt_out' => false],
            'entity_id' => 'person@example.com',
        ]], $this->enqueued, 'The engine hears the opt-in on the queue');
        self::assertTrue($consent->isAllowed('person@example.com', 1));
    }

    public function testSubscribingAgainLeavesAProfilingOptOutOfItsOwnInPlace(): void
    {
        $this->records['person@example.com'] = self::NOW - 3600;
        $consent = $this->consent();

        $consent->optOutOnUnsubscribe('person@example.com');
        $consent->optInOnResubscribe('person@example.com');

        self::assertSame(['person@example.com' => self::NOW - 3600], $this->records);
        self::assertSame([], $this->byUnsubscribe, 'The unsubscribe does not replace the shopper\'s own opt-out');
        self::assertSame([], $this->enqueued);
        self::assertFalse($consent->isAllowed('person@example.com', 1));
    }

    public function testSubscribingWithoutAnOptOutChangesNothing(): void
    {
        $this->consent()->optInOnResubscribe('person@example.com');

        self::assertSame([], $this->enqueued);
    }

    public function testAnOptOutByUnsubscribingIsNotWrittenToTheSmailyContact(): void
    {
        $this->records['person@example.com'] = self::NOW - 3600;
        $this->byUnsubscribe['person@example.com'] = true;
        $this->contact = ['email' => 'person@example.com', 'is_unsubscribed' => '0'];

        self::assertFalse($this->consent()->isAllowed('person@example.com', 1));
        self::assertSame([], $this->smailyWrites, 'The unsubscribe reaches Smaily on its own');
        self::assertSame(['person@example.com' => true], $this->byUnsubscribe);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: bool}>
     */
    public static function smailySideOptOutOrigins(): array
    {
        return [
            'unsubscribe only' => [['email' => 'person@example.com', 'is_unsubscribed' => '1'], true],
            'unsubscribe and profiling opt-out' => [['email' => 'person@example.com', 'is_unsubscribed' => '1',
                'smaily_rec_profiling' => '0'], false],
            'profiling opt-out' => [['email' => 'person@example.com', 'is_unsubscribed' => '0',
                'smaily_rec_profiling' => '0'], false],
        ];
    }

    /**
     * @param array<string, string> $contact
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('smailySideOptOutOrigins')]
    public function testAnOptOutReadFromSmailyKeepsItsOrigin(array $contact, bool $byUnsubscribe): void
    {
        $this->contact = $contact;

        self::assertFalse($this->consent()->isAllowed('person@example.com', 1));
        self::assertSame(['person@example.com' => $byUnsubscribe], $this->byUnsubscribe);
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

    /**
     * PRO-3760: the engine confirmed a customer or an order for these
     * shoppers; the store's opt-out of each one who opted out is sent again,
     * with the moment the store holds.
     */
    public function testAnOptOutIsSentAgainWhenTheEngineConfirmsTheShopper(): void
    {
        $this->records['u1@example.invalid'] = self::NOW - 3600;

        $this->consent()->resendOptOuts(['U1@Example.invalid ', 'u2@example.invalid']);

        self::assertSame([[
            'event_type' => EventType::ENGINE_PROFILING_CONSENT,
            'payload' => [
                'email' => 'u1@example.invalid',
                'opt_out' => true,
                'opted_out_at' => gmdate('Y-m-d\TH:i:s\Z', self::NOW - 3600),
            ],
            'entity_id' => 'u1@example.invalid',
        ]], $this->enqueued);
    }

    public function testAShopperWhoDidNotOptOutGetsNoConsentCall(): void
    {
        $this->consent()->resendOptOuts(['u2@example.invalid', '']);

        self::assertSame([], $this->enqueued);
    }

    /**
     * A mirror of an opt-out read back from Smaily has no moment of its own
     * (0): it is sent with the moment of sending, as when it was mirrored.
     */
    public function testAMirroredOptOutIsSentAgainWithTheMomentOfSending(): void
    {
        $this->records['u1@example.invalid'] = 0;

        $this->consent()->resendOptOuts(['u1@example.invalid']);

        self::assertSame(self::NOW_Z, $this->enqueued[0]['payload']['opted_out_at']);
    }

    /**
     * A shopper with many orders gets one waiting opt-out, not one per order.
     */
    public function testAnOptOutAlreadyWaitingIsNotQueuedAgain(): void
    {
        $this->records['u1@example.invalid'] = self::NOW - 3600;
        $this->records['u2@example.invalid'] = self::NOW - 7200;
        $this->waitingOptOuts = ['u2@example.invalid'];

        $this->consent()->resendOptOuts(['u1@example.invalid', 'u1@example.invalid', 'u2@example.invalid']);

        self::assertSame(['u1@example.invalid'], array_column($this->enqueued, 'entity_id'));
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
        $eventQueue->method('waitingProfilingOptOuts')->willReturnCallback(
            fn (array $emails): array => array_values(array_intersect($emails, $this->waitingOptOuts))
        );

        $optOuts = $this->createMock(ProfilingOptOuts::class);
        $optOuts->method('moments')->willReturnCallback(
            fn (array $emails): array => array_intersect_key($this->records, array_flip($emails))
        );
        $optOuts->method('moment')->willReturnCallback(
            fn (string $email): ?int => $this->records[$email] ?? null
        );
        $optOuts->method('record')->willReturnCallback(
            function (string $email, int $moment, bool $byUnsubscribe = false): void {
                $this->records[$email] = $moment;
                $this->byUnsubscribe[$email] = $byUnsubscribe;
            }
        );
        $optOuts->method('forget')->willReturnCallback(function (string $email): void {
            unset($this->records[$email], $this->byUnsubscribe[$email]);
        });
        $optOuts->method('isByUnsubscribe')->willReturnCallback(
            fn (string $email): bool => isset($this->records[$email]) && ($this->byUnsubscribe[$email] ?? false)
        );
        $optOuts->method('addressKey')->willReturnCallback(
            static fn (string $email): string => self::keyedHash($email)
        );

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
        return 'smaily_profiling_' . self::keyedHash($email);
    }

    /**
     * What the opt-out record keys an address by in this test.
     */
    private static function keyedHash(string $email): string
    {
        return hash_hmac('sha256', $email, 'unit-test-crypt-key');
    }
}
