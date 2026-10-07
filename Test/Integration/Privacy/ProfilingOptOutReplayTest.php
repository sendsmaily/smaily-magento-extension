<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Privacy;

use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Smaily\Connect\Cron\FlushEventQueue;
use Smaily\Connect\Cron\FlushIngestQueue;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Privacy\AddressKey;
use Smaily\Connect\Model\Privacy\OptOutReplay;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\Model\Privacy\ProfilingOptOuts;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\Queue\Handler\ProfilingConsentHandler;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * PRO-3760: an opt-out made before the engine knew the shopper (the engine
 * answers it "not found") reaches the engine once the engine confirms a
 * customer or an order of that shopper. The real ingest flush, the real
 * marketing queue flush and the store's real opt-out record run against a
 * real database; only the engine is faked, and it keeps an opt-out only for
 * a shopper it holds, as contract §10 says.
 */
class ProfilingOptOutReplayTest extends IntegrationTestCase
{
    /** @var array<string, true> the shoppers the fake engine holds */
    private array $engineShoppers = [];

    /** @var array<string, bool> the opt-out state the fake engine holds, by shopper */
    private array $engineOptOuts = [];

    /** @var array<int, array{0: string, 1: bool}> */
    private array $engineConsentCalls = [];

    private Settings&MockObject $settings;
    private Client&MockObject $client;
    private ProfilingOptOuts $optOuts;
    private IngestQueue $ingestQueue;

    protected function setUp(): void
    {
        parent::setUp();
        (new SchemaInstaller($this->connection))->createFlag();

        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isConnected')->willReturn(true);
        $this->settings->method('isSendingAllowed')->willReturn(true);

        $this->client = $this->createMock(Client::class);
        $this->client->method('ingest')->willReturnCallback(function (string $domain, array $items): array {
            foreach ($items as $item) {
                $this->engineShoppers[(string)($item['email'] ?? $item['customer_email'])] = true;
            }

            return ['ok' => true, 'processed' => count($items), 'deduplicated' => 0, 'errors' => []];
        });
        $this->client->method('customerOptOut')->willReturnCallback(
            function (string $email, bool $optOut): array {
                $this->engineConsentCalls[] = [$email, $optOut];
                if (!isset($this->engineShoppers[$email])) {
                    throw new EngineRequestException('Engine request failed with HTTP 404: not found', 404);
                }
                $this->engineOptOuts[$email] = $optOut;

                return ['ok' => true];
            }
        );

        /** @var ProfilingOptOuts $optOuts */
        $optOuts = $this->objectManager->create(ProfilingOptOuts::class, [
            'lockManager' => $this->createMock(LockManagerInterface::class),
        ]);
        $this->optOuts = $optOuts;
        $this->ingestQueue = $this->objectManager->create(IngestQueue::class);
    }

    protected function tearDown(): void
    {
        $this->connection->query('DROP TABLE IF EXISTS `flag`');
        parent::tearDown();
    }

    public function testAnOrderMakesAnOptOutTheEngineDidNotKeepReachIt(): void
    {
        $this->consent()->setAllowed('u1@example.invalid', false, 0);
        $this->flushConsent();
        self::assertSame([], $this->engineOptOuts, 'The engine does not know the shopper yet');

        $this->ingestQueue->enqueue(Client::DOMAIN_ORDERS, $this->order('100', 'u1@example.invalid'), '100');
        $this->flushIngest();
        $this->flushConsent();

        self::assertSame(['u1@example.invalid' => true], $this->engineOptOuts);
        self::assertSame(
            [['u1@example.invalid', true], ['u1@example.invalid', true]],
            $this->engineConsentCalls
        );
        self::assertSame(
            [Event::STATUS_SENT, Event::STATUS_SENT],
            array_column($this->consentRows(), 'status')
        );
    }

    /**
     * A customer save and the customer history import queue the same
     * customer row; the engine confirming it sends the opt-out again.
     */
    public function testAConfirmedCustomerMakesTheOptOutReachTheEngine(): void
    {
        $this->consent()->setAllowed('u1@example.invalid', false, 0);
        $this->flushConsent();

        $this->ingestQueue->enqueue(
            Client::DOMAIN_CUSTOMERS,
            ['email' => 'u1@example.invalid', 'external_id' => '7'],
            '7'
        );
        $this->flushIngest();
        $this->flushConsent();

        self::assertSame(['u1@example.invalid' => true], $this->engineOptOuts);
    }

    public function testAShopperWhoDidNotOptOutIsUntouched(): void
    {
        $this->ingestQueue->enqueue(Client::DOMAIN_ORDERS, $this->order('100', 'u2@example.invalid'), '100');
        $this->ingestQueue->enqueue(
            Client::DOMAIN_CUSTOMERS,
            ['email' => 'u2@example.invalid', 'external_id' => '8'],
            '8'
        );
        $this->flushIngest();
        $this->flushConsent();

        self::assertSame([], $this->consentRows());
        self::assertSame([], $this->engineConsentCalls);
    }

    public function testTheNewestChoiceWinsAfterTheShopperOptedInAgain(): void
    {
        $consent = $this->consent();
        $consent->setAllowed('u1@example.invalid', false, 0);
        $consent->setAllowed('u1@example.invalid', true, 0);
        $this->flushConsent();
        $calls = $this->engineConsentCalls;

        $this->ingestQueue->enqueue(Client::DOMAIN_ORDERS, $this->order('100', 'u1@example.invalid'), '100');
        $this->flushIngest();
        $this->flushConsent();

        self::assertCount(2, $this->consentRows(), 'No opt-out is queued again');
        self::assertSame($calls, $this->engineConsentCalls);
        self::assertSame([], $this->engineOptOuts);
    }

    public function testManyOrdersMakeOneWaitingOptOut(): void
    {
        $this->consent()->setAllowed('u1@example.invalid', false, 0);
        $this->flushConsent();

        $this->ingestQueue->enqueue(Client::DOMAIN_ORDERS, $this->order('100', 'u1@example.invalid'), '100');
        $this->ingestQueue->enqueue(Client::DOMAIN_ORDERS, $this->order('101', 'u1@example.invalid'), '101');
        $this->ingestQueue->enqueue(
            Client::DOMAIN_CUSTOMERS,
            ['email' => 'u1@example.invalid', 'external_id' => '7'],
            '7'
        );
        $this->flushIngest();
        $this->ingestQueue->enqueue(Client::DOMAIN_ORDERS, $this->order('102', 'u1@example.invalid'), '102');
        $this->flushIngest();

        self::assertSame(
            [Event::STATUS_SENT, Event::STATUS_PENDING],
            array_column($this->consentRows(), 'status'),
            'One opt-out waits, however many orders were confirmed'
        );

        $this->flushConsent();
        self::assertSame(['u1@example.invalid' => true], $this->engineOptOuts);
        self::assertCount(2, $this->engineConsentCalls);
    }

    /**
     * PRO-3765: the consent row's entity is the shopper's keyed hash, which
     * always fits the 64-character column, so an address longer than that
     * is found waiting too — and the address is stored only in the payload.
     */
    public function testALongAddressMakesOneWaitingOptOutHoweverManyOrders(): void
    {
        $email = str_repeat('l', 70) . '@example.invalid';
        $this->consent()->setAllowed($email, false, 0);
        $this->flushConsent();

        $this->ingestQueue->enqueue(Client::DOMAIN_ORDERS, $this->order('100', $email), '100');
        $this->ingestQueue->enqueue(Client::DOMAIN_ORDERS, $this->order('101', $email), '101');
        $this->flushIngest();
        $this->ingestQueue->enqueue(Client::DOMAIN_ORDERS, $this->order('102', $email), '102');
        $this->flushIngest();

        $rows = $this->consentRows();
        self::assertSame(
            [Event::STATUS_SENT, Event::STATUS_PENDING],
            array_column($rows, 'status'),
            'One opt-out waits, however many orders were confirmed'
        );
        self::assertSame(
            [$this->addressKey($email), $this->addressKey($email)],
            array_column($rows, 'entity_id')
        );

        $this->flushConsent();
        self::assertSame([$email => true], $this->engineOptOuts);
    }

    /**
     * A consent row queued before PRO-3765 carries the plain address; while
     * it waits, a confirmed order queues no second one.
     */
    public function testAnOptOutQueuedBeforeWithThePlainAddressStillCountsAsWaiting(): void
    {
        $this->consent()->setAllowed('u1@example.invalid', false, 0);
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['entity_id' => 'u1@example.invalid'],
            ['event_type = ?' => EventType::ENGINE_PROFILING_CONSENT]
        );

        $this->ingestQueue->enqueue(Client::DOMAIN_ORDERS, $this->order('100', 'u1@example.invalid'), '100');
        $this->flushIngest();

        self::assertSame(['u1@example.invalid'], array_column($this->consentRows(), 'entity_id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function order(string $id, string $email): array
    {
        return ['external_order_id' => $id, 'customer_email' => $email, 'status' => 'completed'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function consentRows(): array
    {
        return array_values(array_filter(
            $this->fetchAll(EventResource::TABLE_NAME),
            static fn (array $row): bool => $row['event_type'] === EventType::ENGINE_PROFILING_CONSENT
        ));
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

    private function addressKey(string $email): string
    {
        /** @var AddressKey $addressKey */
        $addressKey = $this->objectManager->create(AddressKey::class);

        return $addressKey->of($email);
    }

    private function flushIngest(): void
    {
        /** @var FlushIngestQueue $cron */
        $cron = $this->objectManager->create(FlushIngestQueue::class, [
            'settings' => $this->settings,
            'queue' => $this->ingestQueue,
            'catalogIngest' => $this->createMock(CatalogIngest::class),
            'client' => $this->client,
            'optOutReplay' => new OptOutReplay($this->consent()),
        ]);
        $cron->execute();
    }

    private function flushConsent(): void
    {
        /** @var ProfilingConsentHandler $handler */
        $handler = $this->objectManager->create(ProfilingConsentHandler::class, [
            'settings' => $this->settings,
            'client' => $this->client,
            'optOuts' => $this->optOuts,
        ]);
        /** @var FlushEventQueue $cron */
        $cron = $this->objectManager->create(FlushEventQueue::class, [
            'handlerPool' => new HandlerPool([EventType::ENGINE_PROFILING_CONSENT => $handler]),
        ]);
        $cron->execute();
    }
}
