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
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\Handler\IdentityMergeHandler;

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

    protected function setUp(): void
    {
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

    public function testAnOptedOutShopperIsNotMerged(): void
    {
        $this->profilingConsent->method('isAllowed')->with('person@example.com', 3)->willReturn(false);
        $this->client->expects(self::never())->method('identityMerge');

        self::assertSame([1 => true], $this->handle(self::PAYLOAD), 'Closed for good: browsing stays anonymous');
    }

    public function testAShopperWhoseAccountIsGoneIsAskedAtTheDefaultScope(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerRepository->method('getById')->willThrowException(new NoSuchEntityException());
        $this->profilingConsent->expects(self::once())->method('isAllowed')
            ->with('person@example.com', null)->willReturn(false);
        $this->client->expects(self::never())->method('identityMerge');

        self::assertSame([1 => true], $this->handle(self::PAYLOAD));
    }

    /**
     * @param array<string, string> $payload
     * @return array<int, true|string|\Smaily\Connect\Model\Client\Exception\SmailyClientException>
     */
    private function handle(array $payload): array
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);

        $event = $this->createMock(Event::class);
        $event->method('getId')->willReturn(1);
        $eventQueue = $this->createMock(EventQueue::class);
        $eventQueue->method('decodePayload')->willReturn($payload);

        $handler = new IdentityMergeHandler(
            $settings,
            $this->client,
            $eventQueue,
            $this->profilingConsent,
            $this->customerRepository,
            $this->createMock(Logger::class)
        );

        return $handler->handle([$event]);
    }
}
