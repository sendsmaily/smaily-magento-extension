<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Queue\Handler;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\ProfilingOptOuts;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\Failure;
use Smaily\Connect\Model\Queue\Handler\ProfilingConsentHandler;
use Smaily\Connect\Model\Queue\Pending;
use Smaily\Connect\Model\Queue\Skipped;

class ProfilingConsentHandlerTest extends TestCase
{
    private Settings&MockObject $settings;
    private Client&MockObject $client;
    private ProfilingOptOuts&MockObject $optOuts;
    private bool $refused = false;
    private ?string $notConnected = null;

    /** @var array<int, array<string, mixed>> */
    private array $payloads = [];

    /** @var array<int, array{0: array<int|string, mixed>, 1: array<string, mixed>|null}> */
    private array $recorded = [];

    protected function setUp(): void
    {
        $this->payloads = [];
        $this->recorded = [];
        $this->refused = false;
        $this->notConnected = null;
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isConnected')->willReturn(true);
        $this->settings->method('isRefused')->willReturnCallback(fn (): bool => $this->refused);
        $this->settings->method('sendingBlockedReason')->willReturnCallback($this->sendingBlockedReason(...));
        $this->client = $this->createMock(Client::class);
        $this->optOuts = $this->createMock(ProfilingOptOuts::class);
    }

    public function testAnOptOutTheStoreStillHoldsIsSentWithItsMoment(): void
    {
        $this->optOuts->method('moment')->willReturn(1790000000);
        $this->client->expects(self::once())->method('customerOptOut')
            ->with('person@example.com', true, 'user_preference', '2026-09-21T14:13:20Z')
            ->willReturn(['ok' => true]);

        $results = $this->handle(1, ['email' => 'person@example.com', 'opt_out' => true,
            'opted_out_at' => '2026-09-21T14:13:20Z']);

        self::assertSame([1 => true], $results);
    }

    public function testAnOptInIsSentWhenTheStoreHoldsNoOptOut(): void
    {
        $this->optOuts->method('moment')->willReturn(null);
        $this->client->expects(self::once())->method('customerOptOut')
            ->with('person@example.com', false, 'user_preference', '')
            ->willReturn(['ok' => true]);

        self::assertSame([1 => true], $this->handle(1, ['email' => 'person@example.com', 'opt_out' => false]));
    }

    public function testAChoiceANewerOneReplacedIsNotSent(): void
    {
        // The row says opt out; the shopper has since opted back in.
        $this->optOuts->method('moment')->willReturn(null);
        $this->client->expects(self::never())->method('customerOptOut');

        $results = $this->handle(1, ['email' => 'person@example.com', 'opt_out' => true,
            'opted_out_at' => '2026-09-21T14:13:20Z']);

        self::assertEquals(
            [1 => new Skipped(ProfilingConsentHandler::SKIPPED_REPLACED)],
            $results,
            'PRO-3634: closed for good without a call, and it reads Skipped, not delivered'
        );
    }

    public function testAnEngineOutageLeavesTheRowToTheRetryLadder(): void
    {
        $this->optOuts->method('moment')->willReturn(1790000000);
        $this->client->method('customerOptOut')
            ->willThrowException(new EngineTransportException('Engine request failed with HTTP 503 after retries'));

        $results = $this->handle(1, ['email' => 'person@example.com', 'opt_out' => true,
            'opted_out_at' => '2026-09-21T14:13:20Z']);

        self::assertInstanceOf(\Throwable::class, $results[1]);
        $failure = Failure::of($results[1]);
        self::assertFalse($failure->permanent);
        self::assertSame('Engine request failed with HTTP 503 after retries', $failure->reason);
    }

    public function testAnAddressTheEngineDoesNotKnowHasNothingToExclude(): void
    {
        $this->optOuts->method('moment')->willReturn(1790000000);
        $this->client->method('customerOptOut')
            ->willThrowException(new EngineRequestException('Engine request failed with HTTP 404', 404));

        $results = $this->handle(1, ['email' => 'person@example.com', 'opt_out' => true,
            'opted_out_at' => '2026-09-21T14:13:20Z']);

        self::assertSame([1 => true], $results);
    }

    public function testTheChoiceIsRecordedOnTheRowAsTheEngineAnsweredIt(): void
    {
        $exchange = [
            'request' => ['opt_out' => true, 'reason' => 'user_preference', 'opted_out_at' => '2026-09-21T14:13:20Z'],
            'response' => ['http_status' => 404, 'body' => ['error' => 'not_found']],
        ];
        $this->optOuts->method('moment')->willReturn(1790000000);
        $this->client->method('customerOptOut')
            ->willThrowException(new EngineRequestException('Engine request failed with HTTP 404', 404));
        $this->client->method('lastExchange')->willReturn($exchange);

        $this->handle(1, ['email' => 'person@example.com', 'opt_out' => true,
            'opted_out_at' => '2026-09-21T14:13:20Z']);

        self::assertSame([[$exchange['request'], $exchange['response']]], $this->recorded);
    }

    /**
     * PRO-3752: a choice the engine refuses (a 4xx other than 404) can never
     * succeed, so it fails on the first attempt with the engine's reason.
     */
    public function testAChoiceTheEngineRefusesFailsOnTheFirstAttemptWithItsReason(): void
    {
        $this->optOuts->method('moment')->willReturn(1790000000);
        $this->client->method('customerOptOut')
            ->willThrowException(new EngineRequestException('Engine request failed with HTTP 422: invalid email', 422));

        $results = $this->handle(1, ['email' => 'person@example.com', 'opt_out' => true,
            'opted_out_at' => '2026-09-21T14:13:20Z']);

        self::assertInstanceOf(\Throwable::class, $results[1]);
        self::assertEquals(
            Failure::permanent('permanent_http_422: Engine request failed with HTTP 422: invalid email'),
            Failure::of($results[1])
        );
    }

    /**
     * PRO-3752: while Campaign Intelligence refuses the account (contract §2
     * `403 tenant_inactive`, PRO-2451), a choice is left pending as it was —
     * no call, no attempt spent — and the handler says the queue should not
     * claim its rows, as the identity merges wait (PRO-2466).
     */
    public function testWhileTheAccountIsRefusedTheChoiceIsLeftPending(): void
    {
        $this->refused = true;
        $this->client->expects(self::never())->method('customerOptOut');

        $results = $this->handle(1, ['email' => 'person@example.com', 'opt_out' => true]);

        self::assertEquals([1 => new Pending()], $results);
        self::assertTrue($this->handler()->isPaused());
        $this->refused = false;
        self::assertFalse($this->handler()->isPaused());
    }

    /**
     * PRO-3752: the choice that meets the refusal is not this row's fault
     * either: it waits too, instead of failing as a 4xx.
     */
    public function testTheChoiceThatMeetsTheRefusalIsLeftPending(): void
    {
        $this->optOuts->method('moment')->willReturn(1790000000);
        $this->client->method('customerOptOut')->willReturnCallback(function (): array {
            $this->refused = true;
            throw new EngineRequestException('Engine request failed with HTTP 403: tenant_inactive', 403);
        });

        $results = $this->handle(1, ['email' => 'person@example.com', 'opt_out' => true,
            'opted_out_at' => '2026-09-21T14:13:20Z']);

        self::assertEquals([1 => new Pending()], $results);
    }

    public function testNothingIsSentWhileCampaignIntelligenceIsNotConnected(): void
    {
        $this->notConnected = 'Campaign Intelligence is not connected';
        $this->client->expects(self::never())->method('customerOptOut');

        $results = $this->handle(1, ['email' => 'person@example.com', 'opt_out' => true]);

        self::assertSame([1 => 'Campaign Intelligence is not connected'], $results);
    }

    /**
     * PRO-1961: a payload without an address never improves on retry.
     */
    public function testAPayloadWithoutAnAddressFailsForGood(): void
    {
        $this->client->expects(self::never())->method('customerOptOut');

        self::assertEquals(
            [1 => Failure::permanent('Malformed profiling consent payload')],
            $this->handle(1, ['opt_out' => true])
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, mixed>
     */
    private function handle(int $id, array $payload): array
    {
        $event = $this->createMock(Event::class);
        $event->method('getId')->willReturn($id);
        $this->payloads[$id] = $payload;

        $eventQueue = $this->createMock(EventQueue::class);
        $eventQueue->method('decodePayload')->willReturnCallback(
            fn (Event $event): array => $this->payloads[(int)$event->getId()]
        );
        $eventQueue->method('recordExchange')->willReturnCallback(
            function (Event $event, array $sent, ?array $response): void {
                $this->recorded[] = [$sent, $response];
            }
        );

        return $this->handler($eventQueue)->handle([$event]);
    }

    private function handler(?EventQueue $eventQueue = null): ProfilingConsentHandler
    {
        return new ProfilingConsentHandler(
            $this->settings,
            $this->client,
            $eventQueue ?? $this->createMock(EventQueue::class),
            $this->optOuts,
            $this->createMock(Logger::class)
        );
    }

    /**
     * Settings::sendingBlockedReason() for the state the test set.
     */
    private function sendingBlockedReason(): ?string
    {
        return $this->refused ? 'Campaign Intelligence account is not active' : $this->notConnected;
    }
}
