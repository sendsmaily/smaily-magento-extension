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
use Smaily\Connect\Model\Queue\Handler\ProfilingConsentHandler;

class ProfilingConsentHandlerTest extends TestCase
{
    private Settings&MockObject $settings;
    private Client&MockObject $client;
    private ProfilingOptOuts&MockObject $optOuts;

    /** @var array<int, array<string, mixed>> */
    private array $payloads = [];

    protected function setUp(): void
    {
        $this->payloads = [];
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isConnected')->willReturn(true);
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

        self::assertSame([1 => true], $results, 'Closed for good: retrying it would undo the newer choice');
    }

    public function testAnEngineOutageLeavesTheRowToTheRetryLadder(): void
    {
        $this->optOuts->method('moment')->willReturn(1790000000);
        $this->client->method('customerOptOut')
            ->willThrowException(new EngineTransportException('Engine request failed with HTTP 503 after retries'));

        $results = $this->handle(1, ['email' => 'person@example.com', 'opt_out' => true,
            'opted_out_at' => '2026-09-21T14:13:20Z']);

        self::assertSame([1 => 'Engine request failed with HTTP 503 after retries'], $results);
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

    public function testOtherRefusalsAreReported(): void
    {
        $this->optOuts->method('moment')->willReturn(1790000000);
        $this->client->method('customerOptOut')
            ->willThrowException(new EngineRequestException('Engine request failed with HTTP 422', 422));

        $results = $this->handle(1, ['email' => 'person@example.com', 'opt_out' => true,
            'opted_out_at' => '2026-09-21T14:13:20Z']);

        self::assertSame([1 => 'Engine request failed with HTTP 422'], $results);
    }

    public function testNothingIsSentWhileTheAccountIsRefused(): void
    {
        $this->settings->method('isRefused')->willReturn(true);
        $this->client->expects(self::never())->method('customerOptOut');

        $results = $this->handle(1, ['email' => 'person@example.com', 'opt_out' => true]);

        self::assertSame([1 => 'Campaign Intelligence account is not active'], $results);
    }

    public function testAPayloadWithoutAnAddressIsReported(): void
    {
        $this->client->expects(self::never())->method('customerOptOut');

        self::assertSame([1 => 'Malformed profiling consent payload'], $this->handle(1, ['opt_out' => true]));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, true|string|\Smaily\Connect\Model\Client\Exception\SmailyClientException>
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

        $handler = new ProfilingConsentHandler(
            $this->settings,
            $this->client,
            $eventQueue,
            $this->optOuts,
            $this->createMock(Logger::class)
        );

        return $handler->handle([$event]);
    }
}
