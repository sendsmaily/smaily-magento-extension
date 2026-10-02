<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Queue\Handler;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Automation\WorkflowMatch;
use Smaily\Connect\Model\Client\Exception\TransportException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\Handler\AutomationHandler;

class AutomationHandlerTest extends TestCase
{
    /**
     * PRO-3577: an automation trigger never re-subscribes a contact who
     * unsubscribed in Smaily. The handler reads no setting for it, so a
     * store that saved the retired "force opt-in" setting on still sends
     * force_opt_in=false.
     */
    public function testATriggerNeverForcesOptIn(): void
    {
        $posted = [];
        $client = $this->createMock(SmailyClient::class);
        $client->method('post')->willReturnCallback(
            static function (string $endpoint, array $payload) use (&$posted): array {
                $posted[] = $payload;

                return [];
            }
        );
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->method('forStore')->willReturn($client);

        $router = $this->createMock(Router::class);
        $router->method('resolve')->willReturn(new WorkflowMatch(7));

        $event = $this->createMock(Event::class);
        $event->method('getId')->willReturn(1);
        $eventQueue = $this->createMock(EventQueue::class);
        $eventQueue->method('decodePayload')->willReturn([
            'trigger_type' => 'welcome',
            'store_id' => 1,
            'website_id' => 1,
            'language' => 'en',
            'address' => ['email' => 'person@example.com'],
        ]);

        $handler = new AutomationHandler(
            $clientProvider,
            $router,
            $eventQueue,
            $this->createMock(Logger::class),
            $this->createMock(AccountResolver::class)
        );

        self::assertSame([1 => true], $handler->handle([$event]));
        self::assertCount(1, $posted);
        self::assertFalse($posted[0]['force_opt_in']);
    }

    /**
     * PRO-1965: the Log's Details show what this row put on the wire and
     * what Smaily answered — a refusal included.
     */
    public function testTheRowRecordsTheRequestAsPostedAndTheReply(): void
    {
        $exchange = [
            'request' => ['autoresponder' => 7, 'addresses' => [['email' => 'person@example.com']]],
            'response' => ['http_status' => 404, 'body' => ['code' => 404, 'message' => 'Not found']],
        ];
        $refusal = new TransportException('Smaily API request failed with HTTP 404', 404);
        $client = $this->createMock(SmailyClient::class);
        $client->method('post')->willThrowException($refusal);
        $client->method('lastExchange')->willReturn($exchange);
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->method('forStore')->willReturn($client);

        $router = $this->createMock(Router::class);
        $router->method('resolve')->willReturn(new WorkflowMatch(7));

        $event = $this->createMock(Event::class);
        $event->method('getId')->willReturn(1);
        $eventQueue = $this->createMock(EventQueue::class);
        $eventQueue->method('decodePayload')->willReturn([
            'trigger_type' => 'welcome',
            'address' => ['email' => 'person@example.com'],
        ]);
        $eventQueue->expects(self::once())->method('recordExchange')
            ->with($event, $exchange['request'], $exchange['response']);

        $handler = new AutomationHandler(
            $clientProvider,
            $router,
            $eventQueue,
            $this->createMock(Logger::class),
            $this->createMock(AccountResolver::class)
        );

        self::assertSame([1 => $refusal], $handler->handle([$event]));
    }
}
