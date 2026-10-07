<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Queue\Handler;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\Failure;
use Smaily\Connect\Model\Queue\Handler\IdentityMergeHandler;
use Smaily\Connect\Model\Queue\Pending;
use Smaily\Connect\Model\Queue\Skipped;

class IdentityMergeHandlerTest extends TestCase
{
    private const PAYLOAD = [
        'customer_email' => 'person@example.com',
        'customer_external_id' => '42',
        'merge_ts' => '2026-09-21T14:13:20Z',
        'merge_reason' => 'login',
        'anon_session_id' => '0b7d3f7e-2c4f-4a8e-9d55-3f1d2e6c7a90',
    ];

    private Client&MockObject $client;
    private ProfilingConsent&MockObject $profilingConsent;
    private CustomerRepositoryInterface&MockObject $customerRepository;

    /** @var array<int, array{0: array<int|string, mixed>, 1: array<string, mixed>|null}> */
    private array $recorded = [];

    protected function setUp(): void
    {
        $this->recorded = [];
        $this->client = $this->createMock(Client::class);
        $this->profilingConsent = $this->createMock(ProfilingConsent::class);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getStoreId')->willReturn(3);
        $this->customerRepository->method('getById')->with(42)->willReturn($customer);
    }

    public function testAShopperWhoAllowsProfilingIsMerged(): void
    {
        $this->profilingConsent->method('isAllowed')->with('person@example.com', 3)->willReturn(true);
        $this->client->expects(self::once())->method('identityMerge')->with(self::PAYLOAD)->willReturn(['ok' => true]);

        self::assertSame([1 => true], $this->handle(self::PAYLOAD));
    }

    public function testTheMergeIsRecordedOnTheRow(): void
    {
        $exchange = ['request' => self::PAYLOAD, 'response' => ['http_status' => 200, 'body' => ['ok' => true]]];
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $this->client->method('identityMerge')->willReturn(['ok' => true]);
        $this->client->method('lastExchange')->willReturn($exchange);

        $this->handle(self::PAYLOAD);

        self::assertSame([[self::PAYLOAD, $exchange['response']]], $this->recorded);
    }

    public function testAnOptedOutShopperIsNotMerged(): void
    {
        $this->profilingConsent->method('isAllowed')->with('person@example.com', 3)->willReturn(false);
        $this->client->expects(self::never())->method('identityMerge');

        self::assertEquals(
            [1 => new Skipped(IdentityMergeHandler::SKIPPED_OPTED_OUT)],
            $this->handle(self::PAYLOAD),
            'PRO-3634: closed for good without a call, and it reads Skipped, not delivered'
        );
        self::assertSame([], $this->recorded, 'Nothing went on the wire');
    }

    public function testAShopperWhoseAccountIsGoneIsAskedAtTheDefaultScope(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerRepository->method('getById')->willThrowException(new NoSuchEntityException());
        $this->profilingConsent->expects(self::once())->method('isAllowed')
            ->with('person@example.com', null)->willReturn(false);
        $this->client->expects(self::never())->method('identityMerge');

        self::assertEquals([1 => new Skipped(IdentityMergeHandler::SKIPPED_OPTED_OUT)], $this->handle(self::PAYLOAD));
    }

    public function testTheStoreViewQueuedWithTheRowIsUsedAndNotSentToTheEngine(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerRepository->expects(self::never())->method('getById');
        $this->profilingConsent->method('isAllowed')->with('person@example.com', 5)->willReturn(true);
        $this->client->expects(self::once())->method('identityMerge')->with(self::PAYLOAD)->willReturn(['ok' => true]);

        self::assertSame([1 => true], $this->handle(self::PAYLOAD + ['store_id' => 5]));
    }

    /**
     * PRO-1961: a 4xx is a refusal of this payload, which retrying cannot
     * change. Handed on whole, so the queue classifies it on the first
     * attempt; an outage stays an outage.
     */
    public function testAnEngineRefusalIsHandedOnForTheQueueToClassify(): void
    {
        $refusal = new EngineRequestException('Engine request failed with HTTP 422: unknown session', 422);
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $this->client->method('identityMerge')->willThrowException($refusal);

        self::assertSame([1 => $refusal], $this->handle(self::PAYLOAD));
    }

    public function testAnEngineOutageIsHandedOnForTheQueueToClassify(): void
    {
        $outage = new EngineTransportException('Engine request failed with HTTP 503 after retries', 503);
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $this->client->method('identityMerge')->willThrowException($outage);

        self::assertSame([1 => $outage], $this->handle(self::PAYLOAD));
    }

    public function testAMalformedPayloadFailsForGood(): void
    {
        $this->client->expects(self::never())->method('identityMerge');

        self::assertEquals(
            [1 => Failure::permanent('Malformed identity merge payload')],
            $this->handle(['customer_external_id' => '42'])
        );
    }

    /**
     * PRO-2466: while Campaign Intelligence refuses the account (contract §2
     * `403 tenant_inactive`, PRO-2451), a row is left pending as it was —
     * no call, no attempt spent — and the handler says the queue should not
     * claim its rows, as the engine ingest rows wait.
     */
    public function testWhileTheAccountIsRefusedTheRowIsLeftPending(): void
    {
        $this->client->expects(self::never())->method('identityMerge');
        $refused = true;
        $active = false;

        self::assertEquals([1 => new Pending()], $this->handle(self::PAYLOAD, $refused));
        self::assertTrue($this->handler($refused)->isPaused());
        self::assertFalse($this->handler($active)->isPaused());
    }

    /**
     * PRO-2466: the merge that meets the refusal is not this row's fault
     * either: it waits too, instead of failing as a 4xx.
     */
    public function testTheMergeThatMeetsTheRefusalIsLeftPending(): void
    {
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $refused = false;
        $this->client->method('identityMerge')->willReturnCallback(static function () use (&$refused): array {
            $refused = true;
            throw new EngineRequestException('Engine request failed with HTTP 403: tenant_inactive', 403);
        });

        self::assertEquals([1 => new Pending()], $this->handle(self::PAYLOAD, $refused));
    }

    /**
     * @param array<string, string|int> $payload
     * @return array<int, mixed>
     */
    private function handle(array $payload, bool &$refused = false): array
    {
        $event = $this->createMock(Event::class);
        $event->method('getId')->willReturn(1);
        $eventQueue = $this->createMock(EventQueue::class);
        $eventQueue->method('decodePayload')->willReturn($payload);
        $eventQueue->method('recordExchange')->willReturnCallback(
            function (Event $event, array $sent, ?array $response): void {
                $this->recorded[] = [$sent, $response];
            }
        );

        return $this->handler($refused, $eventQueue)->handle([$event]);
    }

    private function handler(bool &$refused, ?EventQueue $eventQueue = null): IdentityMergeHandler
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);
        $settings->method('isRefused')->willReturnCallback(static function () use (&$refused): bool {
            return $refused;
        });
        $settings->method('sendingBlockedReason')->willReturnCallback(static function () use (&$refused): ?string {
            return $refused ? 'Campaign Intelligence account is not active' : null;
        });

        return new IdentityMergeHandler(
            $settings,
            $this->client,
            $eventQueue ?? $this->createMock(EventQueue::class),
            $this->profilingConsent,
            $this->customerRepository,
            $this->createMock(Logger::class)
        );
    }
}
