<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\ContactSync;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Smaily\Connect\Cron\FlushEventQueue;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\StaleSubscription;
use Smaily\Connect\Model\Log\FailureMessage;
use Smaily\Connect\Model\Log\QueueRowLoader;
use Smaily\Connect\Model\Log\Resend;
use Smaily\Connect\Model\Log\ResendGuard;
use Smaily\Connect\Model\Log\SelectionRetry;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\Queue\Handler\ContactSyncHandler;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\Report\ConnectionHealth;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * CON-394: a late or retried contact sync never puts a stale subscription
 * into Smaily — through the real queue, the real contact-sync handler and
 * Smaily client (only the HTTP transport faked), and the Log's "Send again"
 * and "Retry".
 */
class StaleSubscriptionTest extends IntegrationTestCase
{
    private const EMAIL = 'jane@example.com';
    private const OK = '{"code":101,"message":"OK"}';

    private EventQueue $queue;

    /** @var array<int, array{request: Request}> what the fake transport received */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(EventQueue::class);
    }

    public function testTheRetryOfASubscriptionIsSkippedAfterANewerUnsubscribeWasDelivered(): void
    {
        $this->change(false);
        $this->runCron([new Response(503, [], 'Service Unavailable')]);
        self::assertSame(Event::STATUS_PENDING, $this->statusOf(1), 'The subscription waits for its retry');

        $this->change(true);
        $this->runCron([new Response(200, [], self::OK)]);
        self::assertSame(Event::STATUS_SENT, $this->statusOf(2), 'The unsubscribe was delivered');

        $this->clock->travel(EventQueue::BACKOFF_SECONDS[0]);
        $this->requests = [];
        $this->runCron([]);

        self::assertSame([], $this->requests, 'Nothing marks the contact subscribed');
        $row = (array)$this->objectManager->create(QueueRowLoader::class)->load('smaily-1');
        self::assertSame(Collection::STATUS_SKIPPED, $row['status']);
        self::assertSame(ContactSyncHandler::SKIPPED_STALE_SUBSCRIPTION, $row['last_error']);
        self::assertSame(
            ContactSyncHandler::SKIPPED_STALE_SUBSCRIPTION,
            $this->objectManager->create(FailureMessage::class)->forDisplay((string)$row['last_error']),
            'The Log shows the reason, a sentence of ours'
        );
    }

    public function testALateUnsubscribeIsStillDelivered(): void
    {
        $this->change(true);
        $this->runCron([new Response(503, [], 'Service Unavailable')]);
        $this->change(false);
        $this->runCron([new Response(200, [], self::OK)]);

        $this->clock->travel(EventQueue::BACKOFF_SECONDS[0]);
        $this->requests = [];
        $this->runCron([new Response(200, [], self::OK)]);

        self::assertSame(Event::STATUS_SENT, $this->statusOf(1));
        self::assertSame([[1]], $this->postedStatuses());
    }

    public function testOnlyADeliveredChangeWithAStatusMakesASubscriptionStale(): void
    {
        $this->change(false);
        $this->runCron([new Response(503, [], 'Service Unavailable')]);
        // A profile save carries no status: it is no choice of the contact's.
        $this->enqueue(['email' => self::EMAIL, 'first_name' => 'Jane']);
        $this->runCron([new Response(200, [], self::OK)]);
        // A newer unsubscribe that has not been delivered yet.
        $this->change(true);
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['next_retry_at' => $this->clockDate(3600)],
            ['id = ?' => 3]
        );

        $this->clock->travel(EventQueue::BACKOFF_SECONDS[0]);
        $this->requests = [];
        $this->runCron([new Response(200, [], self::OK)]);

        self::assertSame(Event::STATUS_SENT, $this->statusOf(1));
        self::assertSame([[0]], $this->postedStatuses(), 'The subscription goes; the unsubscribe follows it');
    }

    /**
     * Two waiting changes of a contact that come due together go in one
     * request in the order they were made, whatever order the queue hands
     * them over in: the newer one is the contact's state in Smaily.
     */
    public function testTwoWaitingChangesGoInTheOrderTheyWereMade(): void
    {
        $this->change(false);
        $this->change(true);
        $this->dueIn(1, 60);
        $this->dueIn(2, 30);

        $this->clock->travel(60);
        $this->runCron([new Response(200, [], self::OK)]);

        self::assertSame([[0, 1]], $this->postedStatuses());
    }

    /**
     * The same address in two Smaily accounts is two contacts; two store
     * views that send through one account share it.
     */
    public function testTheSameContactIsTheSameAddressInTheSameSmailyAccount(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getSubdomain')->willReturnMap([[1, 'shop-a'], [2, 'shop-b'], [3, 'shop-a']]);
        $stale = $this->objectManager->create(StaleSubscription::class, ['config' => $config]);

        $this->change(false, 1);
        $this->change(false, 2);
        $this->change(true, 2);
        $this->change(true, 3);
        $this->markDelivered(3);

        self::assertSame([2], $stale->among([1 => self::EMAIL, 2 => self::EMAIL]));

        $this->markDelivered(4);
        self::assertSame([1, 2], $stale->among([1 => self::EMAIL, 2 => self::EMAIL]));
    }

    public function testSendAgainAndRetryRefuseAStaleSubscription(): void
    {
        $this->change(false);
        $this->markFailed(1);
        $this->change(true);
        $this->markDelivered(2);

        $loader = $this->objectManager->create(QueueRowLoader::class);
        $guard = $this->objectManager->create(ResendGuard::class);
        $row = (array)$loader->load('smaily-1');
        $reason = $guard->refusalReason(Collection::SOURCE_SMAILY, 1, $row);
        self::assertSame(ResendGuard::REASON_NEWER_SUBSCRIPTION, $reason, 'Send again is refused');
        self::assertStringContainsString('newer subscription change', (string)$guard->message($reason));

        [$retried, $skipped] = $this->objectManager->create(SelectionRetry::class)
            ->retry($this->objectManager->create(Collection::class));
        self::assertSame([0, 1], [$retried, $skipped], 'Retry leaves it alone and counts it');
        self::assertSame(Event::STATUS_FAILED, $this->statusOf(1));
    }

    /**
     * A "Send again" row stands where the row it sends again stood: in one
     * batch with a newer change it goes before that change.
     */
    public function testASendAgainRowGoesBeforeANewerChangeOfTheSameBatch(): void
    {
        $this->change(false);
        $this->markFailed(1);
        $this->change(true);
        $this->sendAgain(1);
        // Row 2 (the newer unsubscribe) and row 3 (the Send again) in one
        // batch, which the queue hands over in its own order, row 2 first.
        $this->dueIn(2, 30);
        $this->dueIn(3, 60);
        $this->clock->travel(60);
        $this->runCron([new Response(200, [], self::OK)]);

        self::assertSame([[0, 1]], $this->postedStatuses(), 'The older subscription goes first');
        self::assertSame([Event::STATUS_SENT, Event::STATUS_SENT], [$this->statusOf(2), $this->statusOf(3)]);
    }

    /**
     * A newer change delivered after a Send again of a subscription was
     * queued makes that Send again stale, though its row is the newer one.
     */
    public function testASendAgainOfASubscriptionIsSkippedOnceANewerChangeWasDelivered(): void
    {
        $this->change(false);
        $this->markFailed(1);
        $this->change(true);
        $this->sendAgain(1);
        $this->dueIn(3, 60);

        $this->runCron([new Response(200, [], self::OK)]);
        self::assertSame([[1]], $this->postedStatuses(), 'The unsubscribe was delivered');

        $this->clock->travel(60);
        $this->requests = [];
        $this->runCron([]);

        self::assertSame([], $this->requests, 'Nothing marks the contact subscribed');
        self::assertSame(
            ContactSyncHandler::SKIPPED_STALE_SUBSCRIPTION,
            $this->fetchRow(EventResource::TABLE_NAME, 3)['last_error']
        );
    }

    /**
     * The Log's "Send again" of a row, as its route runs it: the guard
     * clears it, then the row is queued again.
     */
    private function sendAgain(int $id): void
    {
        $row = (array)$this->objectManager->create(QueueRowLoader::class)->load('smaily-' . $id);
        self::assertSame(
            '',
            $this->objectManager->create(ResendGuard::class)->refusalReason(Collection::SOURCE_SMAILY, $id, $row)
        );
        self::assertTrue($this->objectManager->create(Resend::class)->resend($row, 'admin'));
    }

    /**
     * Queue a newsletter status change, as SyncDispatcher writes it.
     */
    private function change(bool $isUnsubscribed, int $storeId = 1): void
    {
        $this->enqueue(['email' => self::EMAIL, 'is_unsubscribed' => $isUnsubscribed ? 1 : 0], $storeId);
    }

    /**
     * @param array<string, mixed> $contact
     */
    private function enqueue(array $contact, int $storeId = 1): void
    {
        $this->queue->enqueue(EventType::CONTACT_SYNC, ['store_id' => $storeId, 'contact' => $contact], self::EMAIL, 1);
    }

    private function markFailed(int $id): void
    {
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['status' => Event::STATUS_FAILED, 'attempts' => 5, 'last_error' => 'Smaily API request failed: 503'],
            ['id = ?' => $id]
        );
    }

    private function dueIn(int $id, int $seconds): void
    {
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['next_retry_at' => $this->clockDate($seconds)],
            ['id = ?' => $id]
        );
    }

    private function markDelivered(int $id): void
    {
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['status' => Event::STATUS_SENT, 'sent_payload' => '[{"email":"jane@example.com"}]'],
            ['id = ?' => $id]
        );
    }

    private function statusOf(int $id): string
    {
        return (string)$this->fetchRow(EventResource::TABLE_NAME, $id)['status'];
    }

    /**
     * The is_unsubscribed of each contact of each request posted.
     *
     * @return list<list<int>>
     */
    private function postedStatuses(): array
    {
        return array_map(
            static fn (array $entry): array => array_map(
                static fn (array $contact): int => (int)$contact['is_unsubscribed'],
                (array)json_decode((string)$entry['request']->getBody(), true)
            ),
            $this->requests
        );
    }

    /**
     * One flush through the real contact-sync handler, Smaily answering
     * $responses in turn.
     *
     * @param Response[] $responses
     */
    private function runCron(array $responses): void
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($this->requests));
        $httpClientFactory = $this->createMock(HttpClientFactory::class);
        $httpClientFactory->method('create')->willReturnCallback(
            static function (array $config) use ($handlerStack): HttpClient {
                $config['handler'] = $handlerStack;
                return new HttpClient($config);
            }
        );
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->method('forStore')->willReturn(new SmailyClient(
            $httpClientFactory,
            $this->objectManager->get(Logger::class),
            $this->createMock(VerifiedCredentials::class),
            $this->createMock(ConnectionHealth::class),
            'demo',
            'user',
            'secret'
        ));

        $this->objectManager->create(FlushEventQueue::class, [
            'handlerPool' => new HandlerPool([
                EventType::CONTACT_SYNC => $this->objectManager->create(
                    ContactSyncHandler::class,
                    ['clientProvider' => $clientProvider]
                ),
            ]),
        ])->execute();
    }
}
