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

    /** @var array<int, array{0: string, 1: bool}> */
    private array $engineCalls = [];

    private int $engineFailuresLeft = 0;

    private Settings&MockObject $settings;
    private ProfilingOptOuts&MockObject $optOuts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isConnected')->willReturn(true);

        $this->optOuts = $this->createMock(ProfilingOptOuts::class);
        $this->optOuts->method('moment')->willReturnCallback(
            fn (string $email): ?int => $this->records[$email] ?? null
        );
        $this->optOuts->method('record')->willReturnCallback(function (string $email, int $moment): void {
            $this->records[$email] = $moment;
        });
        $this->optOuts->method('forget')->willReturnCallback(function (string $email): void {
            unset($this->records[$email]);
        });
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
