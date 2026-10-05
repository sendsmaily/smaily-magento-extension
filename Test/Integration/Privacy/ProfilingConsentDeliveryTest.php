<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Privacy;

use PHPUnit\Framework\MockObject\MockObject;
use Smaily\Connect\Cron\FlushEventQueue;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\Model\Privacy\ProfilingOptOuts;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\Queue\Handler\ProfilingConsentHandler;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * A shopper's profiling choice travels to the engine through the marketing
 * event queue (PRO-3578): written by ProfilingConsent, drained by the real
 * flush cron and handler against a real database, with only the engine
 * client and the store's opt-out record stubbed.
 */
class ProfilingConsentDeliveryTest extends IntegrationTestCase
{
    /** @var array<string, int> */
    private array $records = [];

    /** @var array<string, bool> */
    private array $byUnsubscribe = [];

    /** @var array<int, array{0: string, 1: bool}> */
    private array $engineCalls = [];

    private int $engineFailuresLeft = 0;

    private ?\Throwable $engineRefusal = null;

    private bool $refused = false;

    private Settings&MockObject $settings;
    private ProfilingOptOuts&MockObject $optOuts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isConnected')->willReturn(true);
        $this->settings->method('isRefused')->willReturnCallback(fn (): bool => $this->refused);

        $this->optOuts = $this->createMock(ProfilingOptOuts::class);
        $this->optOuts->method('moment')->willReturnCallback(
            fn (string $email): ?int => $this->records[$email] ?? null
        );
        $this->optOuts->method('record')->willReturnCallback(
            function (string $email, int $moment, bool $byUnsubscribe = false): void {
                $this->records[$email] = $moment;
                $this->byUnsubscribe[$email] = $byUnsubscribe;
            }
        );
        $this->optOuts->method('forget')->willReturnCallback(function (string $email): void {
            unset($this->records[$email], $this->byUnsubscribe[$email]);
        });
        $this->optOuts->method('isByUnsubscribe')->willReturnCallback(
            fn (string $email): bool => isset($this->records[$email]) && ($this->byUnsubscribe[$email] ?? false)
        );
    }

    public function testAnOptOutSurvivesAnEngineOutage(): void
    {
        $this->engineFailuresLeft = 1;
        // Smaily is down as well: the engine still hears the choice.
        $this->consent()->setAllowed('person@example.com', false, 0);

        $this->runCron();
        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(EventType::ENGINE_PROFILING_CONSENT, $row['event_type']);
        self::assertSame(Event::STATUS_PENDING, $row['status'], 'An outage is retried, never dropped');
        self::assertSame('1', (string)$row['attempts']);
        self::assertSame($this->clockDate(EventQueue::BACKOFF_SECONDS[0]), $row['next_retry_at']);

        $this->clock->travel(EventQueue::BACKOFF_SECONDS[0]);
        $this->runCron();

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_SENT, $row['status']);
        self::assertSame(
            [['person@example.com', true], ['person@example.com', true]],
            $this->engineCalls
        );
    }

    public function testAnOlderChoiceStillOnTheLadderCannotUndoANewerOne(): void
    {
        $this->engineFailuresLeft = 1;
        $this->consent()->setAllowed('person@example.com', false, 0);
        $this->runCron();

        // The shopper opts back in while the opt-out waits for its retry.
        $this->consent()->setAllowed('person@example.com', true, 0);
        $this->clock->travel(EventQueue::BACKOFF_SECONDS[0]);
        $this->runCron();

        $rows = $this->fetchAll(EventResource::TABLE_NAME);
        self::assertSame([Event::STATUS_SENT, Event::STATUS_SENT], array_column($rows, 'status'));
        self::assertSame(
            [['person@example.com', true], ['person@example.com', false]],
            $this->engineCalls,
            'The opt-out is not sent again after the opt-in'
        );
    }

    /**
     * PRO-3752: while Campaign Intelligence refuses the account, the choice
     * waits — not claimed, no attempt spent, nothing written on it — and is
     * sent once the account is active again.
     */
    public function testAChoiceWaitsWhileTheAccountIsRefusedAndIsSentOnceItIsActive(): void
    {
        $this->refused = true;
        $this->consent()->setAllowed('person@example.com', false, 0);

        $this->runCron();
        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_PENDING, $row['status']);
        self::assertSame('0', (string)$row['attempts']);
        self::assertNull($row['claim_token'], 'Never claimed');
        self::assertNull($row['last_error']);
        self::assertNull($row['next_retry_at']);
        self::assertSame([], $this->engineCalls);

        $this->refused = false;
        $this->runCron();

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_SENT, $row['status']);
        self::assertNull($row['last_error']);
        self::assertSame([['person@example.com', true]], $this->engineCalls);
    }

    /**
     * PRO-3752 with PRO-3578: of two choices that waited for one shopper,
     * only the newest reaches the engine once the account is active again.
     */
    public function testOfTwoWaitingChoicesTheNewestWinsOnDelivery(): void
    {
        $this->refused = true;
        $this->consent()->setAllowed('person@example.com', false, 0);
        $this->consent()->setAllowed('person@example.com', true, 0);
        $this->runCron();
        self::assertSame(
            [Event::STATUS_PENDING, Event::STATUS_PENDING],
            array_column($this->fetchAll(EventResource::TABLE_NAME), 'status')
        );

        $this->refused = false;
        $this->runCron();

        [$optOut, $optIn] = $this->fetchAll(EventResource::TABLE_NAME);
        self::assertSame(ProfilingConsentHandler::SKIPPED_REPLACED, $optOut['last_error']);
        self::assertSame(Event::STATUS_SENT, $optIn['status']);
        self::assertNull($optIn['last_error']);
        self::assertSame([['person@example.com', false]], $this->engineCalls, 'The older opt-out is never sent');
    }

    /**
     * PRO-3752: a choice the engine refuses as invalid can never succeed, so
     * it fails on the first attempt with the engine's reason.
     */
    public function testAChoiceTheEngineRefusesFailsOnTheFirstAttempt(): void
    {
        $this->engineRefusal = new EngineRequestException('Engine request failed with HTTP 422: invalid email', 422);
        $this->consent()->setAllowed('person@example.com', false, 0);

        $this->runCron();

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_FAILED, $row['status']);
        self::assertSame('1', (string)$row['attempts'], 'The other four attempts are not spent');
        self::assertNull($row['next_retry_at']);
        self::assertSame('permanent_http_422: Engine request failed with HTTP 422: invalid email', $row['last_error']);
    }

    /**
     * PRO-3594 criterion 1: profiling stopped only by the unsubscribe starts
     * again when the shopper subscribes again, and the engine hears it.
     */
    public function testSubscribingAgainAfterAnUnsubscribeSwitchesProfilingBackOn(): void
    {
        $consent = $this->consent();
        $consent->optOutOnUnsubscribe('person@example.com');
        $this->runCron();

        $consent->optInOnResubscribe('person@example.com');
        $this->runCron();

        self::assertTrue($consent->isAllowed('person@example.com', 0));
        self::assertSame(
            [['person@example.com', true], ['person@example.com', false]],
            $this->engineCalls
        );
    }

    /**
     * PRO-3594 criterion 2: a profiling opt-out of the shopper's own outlasts
     * an unsubscribe and the subscription that follows it.
     */
    public function testSubscribingAgainLeavesTheShoppersOwnOptOutInPlace(): void
    {
        $consent = $this->consent();
        $consent->setAllowed('person@example.com', false, 0);
        $consent->optOutOnUnsubscribe('person@example.com');
        $consent->optInOnResubscribe('person@example.com');
        $this->runCron();

        self::assertFalse($consent->isAllowed('person@example.com', 0));
        self::assertSame([['person@example.com', true]], $this->engineCalls);
    }

    private function consent(): ProfilingConsent
    {
        $provider = $this->createMock(SmailyClientProvider::class);
        $provider->method('forStore')->willThrowException(new SmailyClientException('Smaily is down'));

        /** @var ProfilingConsent $consent */
        $consent = $this->objectManager->create(ProfilingConsent::class, [
            'smailyClientProvider' => $provider,
            'engineSettings' => $this->settings,
            'optOuts' => $this->optOuts,
        ]);

        return $consent;
    }

    private function runCron(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('customerOptOut')->willReturnCallback(
            function (string $email, bool $optOut): array {
                $this->engineCalls[] = [$email, $optOut];
                if ($this->engineRefusal !== null) {
                    throw $this->engineRefusal;
                }
                if ($this->engineFailuresLeft > 0) {
                    $this->engineFailuresLeft--;
                    throw new EngineTransportException('Engine request failed with HTTP 503 after retries');
                }

                return ['ok' => true];
            }
        );

        /** @var ProfilingConsentHandler $handler */
        $handler = $this->objectManager->create(ProfilingConsentHandler::class, [
            'settings' => $this->settings,
            'client' => $client,
            'optOuts' => $this->optOuts,
        ]);
        /** @var FlushEventQueue $cron */
        $cron = $this->objectManager->create(FlushEventQueue::class, [
            'handlerPool' => new HandlerPool([EventType::ENGINE_PROFILING_CONSENT => $handler]),
        ]);
        $cron->execute();
    }
}
