<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\MergeCustomerSender;
use Smaily\Connect\Model\Engine\Payload\CustomerPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;

/**
 * CON-262: the customer of an identity merge goes to the engine alone,
 * right before the merge, and the Log records it as a customers row.
 */
class MergeCustomerSenderTest extends TestCase
{
    private const ITEM = ['email' => 'person@example.com', 'external_id' => '42'];

    private CustomerRepositoryInterface&MockObject $customerRepository;
    private Client&MockObject $client;
    private IngestQueue&MockObject $ingestQueue;
    private IngestEvent&MockObject $event;

    protected function setUp(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getStoreId')->willReturn(3);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerRepository->method('getById')->with(42)->willReturn($customer);
        $this->client = $this->createMock(Client::class);
        $this->client->method('lastExchange')->willReturn([
            'request' => ['customers' => [self::ITEM]],
            'response' => ['http_status' => 200, 'body' => ['ok' => true]],
        ]);
        $this->event = $this->createMock(IngestEvent::class);
        $this->ingestQueue = $this->createMock(IngestQueue::class);
        $this->ingestQueue->method('newEvent')->with(Client::DOMAIN_CUSTOMERS, self::ITEM)->willReturn($this->event);
        $this->ingestQueue->method('decodePayload')->with($this->event)
            ->willReturn(self::ITEM + ['event_id' => 'uuid-1']);
    }

    public function testTheEngineTakingTheCustomerIsRecordedDelivered(): void
    {
        $this->event->expects(self::once())->method('addData')->with(['entity_id' => '42', 'store_id' => 3]);
        $this->client->expects(self::once())->method('ingest')
            ->with(Client::DOMAIN_CUSTOMERS, [self::ITEM + ['event_id' => 'uuid-1']])
            ->willReturn(['ok' => true, 'processed' => 1, 'deduplicated' => 0, 'errors' => []]);
        $this->ingestQueue->expects(self::once())->method('recordExchange')
            ->with($this->event, self::ITEM + ['event_id' => 'uuid-1'], ['http_status' => 200, 'body' => ['ok' => true]]);
        $this->ingestQueue->expects(self::once())->method('markSent')->with($this->event);
        $this->ingestQueue->expects(self::never())->method('markFailed');

        self::assertNull($this->sender()->send(42));
    }

    public function testARefusedItemIsRecordedFailedAndItsReasonAnswered(): void
    {
        $this->client->method('ingest')->willReturn([
            'ok' => true,
            'processed' => 0,
            'deduplicated' => 0,
            'errors' => [['index' => 0, 'email' => 'person@example.com', 'field' => 'email', 'message' => 'Invalid']],
        ]);
        $this->ingestQueue->expects(self::once())->method('markFailed')->with($this->event, 'email: Invalid', true);
        $this->ingestQueue->expects(self::never())->method('markSent');

        self::assertSame('email: Invalid', $this->sender()->send(42));
    }

    public function testAFailedRequestIsHandedOnAndNotRecorded(): void
    {
        $outage = new EngineTransportException('Engine request failed with HTTP 503 after retries', 503);
        $this->client->method('ingest')->willThrowException($outage);
        $this->ingestQueue->expects(self::never())->method('markSent');
        $this->ingestQueue->expects(self::never())->method('markFailed');

        $this->expectExceptionObject($outage);
        $this->sender()->send(42);
    }

    public function testNoAccountSendsNothing(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerRepository->method('getById')->willThrowException(new NoSuchEntityException());
        $this->client->expects(self::never())->method('ingest');

        self::assertSame(MergeCustomerSender::NO_ACCOUNT, $this->sender()->send(42));
    }

    private function sender(): MergeCustomerSender
    {
        $builder = $this->createMock(CustomerPayloadBuilder::class);
        $builder->method('build')->willReturn(self::ITEM);

        return new MergeCustomerSender($this->customerRepository, $builder, $this->client, $this->ingestQueue);
    }
}
