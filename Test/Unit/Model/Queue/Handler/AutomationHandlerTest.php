<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Queue\Handler;

use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Automation\WorkflowMatch;
use Smaily\Connect\Model\Client\Exception\ApiException;
use Smaily\Connect\Model\Client\Exception\TransportException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Discount\AutomationDiscount;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\Failure;
use Smaily\Connect\Model\Queue\Handler\AutomationHandler;
use Smaily\Connect\Model\Queue\Skipped;

class AutomationHandlerTest extends TestCase
{
    private EventQueue $eventQueue;

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
            $this->createMock(AccountResolver::class),
            $this->createMock(Config::class),
            $this->createMock(WebsiteOverrides::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(StateManager::class),
            $this->noDiscount()
        );

        self::assertSame([1 => true], $handler->handle([$event]));
        self::assertCount(1, $posted);
        self::assertFalse($posted[0]['force_opt_in']);
    }

    /**
     * PRO-1961: a payload without a trigger or an address never improves on
     * retry, so it fails for good and nothing is posted.
     */
    public function testAMalformedPayloadFailsForGood(): void
    {
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::never())->method('forStore');
        $event = $this->createMock(Event::class);
        $event->method('getId')->willReturn(1);
        $eventQueue = $this->createMock(EventQueue::class);
        $eventQueue->method('decodePayload')->willReturn(['trigger_type' => 'welcome']);

        $handler = new AutomationHandler(
            $clientProvider,
            $this->createMock(Router::class),
            $eventQueue,
            $this->createMock(Logger::class),
            $this->createMock(AccountResolver::class),
            $this->createMock(Config::class),
            $this->createMock(WebsiteOverrides::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(StateManager::class),
            $this->noDiscount()
        );

        self::assertEquals([1 => Failure::permanent('Malformed automation payload')], $handler->handle([$event]));
    }

    /**
     * PRO-3634: with no Smaily workflow mapped to the trigger nothing is
     * posted, and the row is closed as Skipped with the reason — not as
     * delivered.
     */
    public function testATriggerWithNoWorkflowMappedIsSkippedWithTheReason(): void
    {
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::never())->method('forStore');

        $router = $this->createMock(Router::class);
        $router->method('resolve')->willReturn(null);

        $event = $this->createMock(Event::class);
        $event->method('getId')->willReturn(1);
        $eventQueue = $this->createMock(EventQueue::class);
        $eventQueue->method('decodePayload')->willReturn([
            'trigger_type' => 'welcome',
            'website_id' => 1,
            'language' => 'en',
            'address' => ['email' => 'person@example.com'],
        ]);
        $eventQueue->expects(self::never())->method('recordExchange');

        $handler = new AutomationHandler(
            $clientProvider,
            $router,
            $eventQueue,
            $this->createMock(Logger::class),
            $this->createMock(AccountResolver::class),
            $this->createMock(Config::class),
            $this->createMock(WebsiteOverrides::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(StateManager::class),
            $this->noDiscount()
        );

        self::assertEquals(
            [1 => new Skipped(AutomationHandler::SKIPPED_NO_WORKFLOW)],
            $handler->handle([$event])
        );
    }

    /**
     * CON-188: with per-language Smaily accounts, the Log names the language
     * that has no workflow, so the merchant knows which row to fill in.
     */
    public function testAPerLanguageAccountSkipNamesTheContactsLanguage(): void
    {
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::never())->method('forStore');
        $router = $this->createMock(Router::class);
        $router->method('resolve')->willReturn(null);
        $config = $this->createMock(Config::class);
        $config->method('getMultilingualMode')->with(1)->willReturn('a');

        $event = $this->createMock(Event::class);
        $event->method('getId')->willReturn(1);
        $eventQueue = $this->createMock(EventQueue::class);
        $eventQueue->method('decodePayload')->willReturn([
            'trigger_type' => 'welcome',
            'website_id' => 1,
            'language' => 'lv',
            'address' => ['email' => 'person@example.com'],
        ]);

        $handler = new AutomationHandler(
            $clientProvider,
            $router,
            $eventQueue,
            $this->createMock(Logger::class),
            $this->createMock(AccountResolver::class),
            $config,
            $this->createMock(WebsiteOverrides::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(StateManager::class),
            $this->noDiscount()
        );

        self::assertEquals(
            [1 => new Skipped(str_replace('%1', 'LV', AutomationHandler::SKIPPED_NO_LANGUAGE_WORKFLOW))],
            $handler->handle([$event])
        );
    }

    /**
     * CON-190: a website with its own Smaily account starts only that
     * account's workflows; the Log names the website that has none picked.
     */
    public function testAWebsiteWithItsOwnAccountSkipNamesTheWebsite(): void
    {
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::never())->method('forStore');
        $clientProvider->expects(self::never())->method('forWebsite');
        $router = $this->createMock(Router::class);
        $router->method('resolve')->willReturn(null);
        $overrides = $this->createMock(WebsiteOverrides::class);
        $overrides->method('usesOtherAccount')->with(2)->willReturn(true);
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getName')->willReturn('Latvia');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->with(2)->willReturn($website);

        $event = $this->createMock(Event::class);
        $event->method('getId')->willReturn(1);
        $eventQueue = $this->createMock(EventQueue::class);
        $eventQueue->method('decodePayload')->willReturn([
            'trigger_type' => 'welcome',
            'website_id' => 2,
            'language' => 'lv',
            'address' => ['email' => 'person@example.com'],
        ]);

        $handler = new AutomationHandler(
            $clientProvider,
            $router,
            $eventQueue,
            $this->createMock(Logger::class),
            $this->createMock(AccountResolver::class),
            $this->createMock(Config::class),
            $overrides,
            $storeManager,
            $this->createMock(StateManager::class),
            $this->noDiscount()
        );

        self::assertEquals(
            [1 => new Skipped(str_replace('%1', 'Latvia', AutomationHandler::SKIPPED_NO_OWN_ACCOUNT_WORKFLOW))],
            $handler->handle([$event])
        );
    }

    /**
     * CON-189: the account a matched row names decides the credentials. The
     * 'default' account, and a language with no store view left, is the
     * event website's account (its own, else the store-wide one); a
     * language's account is its store view's; a config default follows the
     * event's store view.
     *
     * @return array<string, array{0: WorkflowMatch, 1: string, 2: int}>
     */
    public static function accountCases(): array
    {
        return [
            'a default row' => [new WorkflowMatch(7, 'default'), 'website', 2],
            'a language row' => [new WorkflowMatch(7, 'et'), 'store', 21],
            'a language without a store view' => [new WorkflowMatch(7, 'lv'), 'website', 2],
            'a config default' => [new WorkflowMatch(7), 'store', 20],
        ];
    }

    /**
     * @dataProvider accountCases
     */
    public function testTheMatchedAccountDecidesTheCredentials(
        WorkflowMatch $match,
        string $scope,
        int $scopeId
    ): void {
        $asked = [];
        $client = $this->createMock(SmailyClient::class);
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->method('forStore')->willReturnCallback(
            static function (int|string|null $storeId) use (&$asked, $client): SmailyClient {
                $asked[] = ['store', $storeId];

                return $client;
            }
        );
        $clientProvider->method('forWebsite')->willReturnCallback(
            static function (int $websiteId) use (&$asked, $client): SmailyClient {
                $asked[] = ['website', $websiteId];

                return $client;
            }
        );
        $router = $this->createMock(Router::class);
        $router->method('resolve')->willReturn($match);
        $accountResolver = $this->createMock(AccountResolver::class);
        $accountResolver->method('storeIdForAccountKey')->willReturnCallback(
            static fn (string $accountKey, int $websiteId): ?int => $accountKey === 'et' && $websiteId === 2 ? 21 : null
        );

        $event = $this->createMock(Event::class);
        $event->method('getId')->willReturn(1);
        $eventQueue = $this->createMock(EventQueue::class);
        $eventQueue->method('decodePayload')->willReturn([
            'trigger_type' => 'welcome',
            'store_id' => 20,
            'website_id' => 2,
            'language' => 'en',
            'address' => ['email' => 'person@example.com'],
        ]);

        $handler = new AutomationHandler(
            $clientProvider,
            $router,
            $eventQueue,
            $this->createMock(Logger::class),
            $accountResolver,
            $this->createMock(Config::class),
            $this->createMock(WebsiteOverrides::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(StateManager::class),
            $this->noDiscount()
        );

        self::assertSame([1 => true], $handler->handle([$event]));
        self::assertSame([[$scope, $scopeId]], $asked);
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
            $this->createMock(AccountResolver::class),
            $this->createMock(Config::class),
            $this->createMock(WebsiteOverrides::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(StateManager::class),
            $this->noDiscount()
        );

        self::assertSame([1 => $refusal], $handler->handle([$event]));
    }

    /**
     * CON-195, CON-196: every abandoned-cart reminder reads its contact first.
     * A contact who unsubscribed in Smaily gets none, whether or not a new
     * contact is allowed; an address Smaily does not have gets it only when
     * a new contact is allowed (store consent, or legitimate interest). A
     * skip closes the row as Skipped and the cart as skipped, with nothing
     * posted.
     *
     * @return array<string, array{0: array<int|string, mixed>|\Throwable, 1: bool, 2: ?string}>
     */
    public static function contactReadCases(): array
    {
        $subscribed = ['email' => 'person@example.com', 'is_unsubscribed' => '0'];
        $unsubscribed = ['email' => 'person@example.com', 'is_unsubscribed' => '1'];
        $missing = new ApiException('Could not find requested email address', 206);
        $left = AutomationHandler::SKIPPED_UNSUBSCRIBED_IN_SMAILY;

        return [
            'a subscribed contact, no new contact' => [$subscribed, false, null],
            'a subscribed contact, new contact allowed' => [$subscribed, true, null],
            'a subscribed contact, as a list' => [[['email' => 'person@example.com', 'is_unsubscribed' => 0]], false, null],
            'an unsubscribed contact, no new contact' => [$unsubscribed, false, $left],
            'an unsubscribed contact, new contact allowed' => [$unsubscribed, true, $left],
            'a contact without a status' => [['email' => 'person@example.com'], true, $left],
            'no contact, no new contact' => [$missing, false, AutomationHandler::SKIPPED_NO_MARKETING_CONSENT],
            'no contact, new contact allowed' => [$missing, true, null],
        ];
    }

    /**
     * @dataProvider contactReadCases
     * @param array<int|string, mixed>|\Throwable $contact
     */
    public function testAReminderGoesByTheContactInSmaily(
        array|\Throwable $contact,
        bool $newContact,
        ?string $skipReason
    ): void {
        $client = $this->createMock(SmailyClient::class);
        $read = $client->expects(self::once())->method('get')
            ->with(SmailyClient::ENDPOINT_CONTACT, ['email' => 'person@example.com']);
        $contact instanceof \Throwable ? $read->willThrowException($contact) : $read->willReturn($contact);
        $client->expects($skipReason === null ? self::once() : self::never())->method('post');
        $cartState = $this->createMock(StateManager::class);
        $cartState->expects($skipReason === null ? self::never() : self::once())
            ->method('markReminderSkipped')->with(42);

        $event = $this->reminderEvent($newContact);
        $results = $this->reminderHandler($client, $cartState)->handle([$event]);

        self::assertEquals([1 => $skipReason === null ? true : new Skipped($skipReason)], $results);
    }

    public function testAReminderWaitsWhenSmailyCannotBeRead(): void
    {
        $failure = new TransportException('Smaily API request failed with HTTP 503', 503);
        $client = $this->createMock(SmailyClient::class);
        $client->method('get')->willThrowException($failure);
        $client->expects(self::never())->method('post');
        $cartState = $this->createMock(StateManager::class);
        $cartState->expects(self::never())->method('markReminderSkipped');

        $event = $this->reminderEvent(true);
        $results = $this->reminderHandler($client, $cartState)->handle([$event]);

        self::assertSame([1 => $failure], $results);
    }

    public function testAnotherAutomationIsPostedWithoutAContactRead(): void
    {
        $client = $this->createMock(SmailyClient::class);
        $client->expects(self::never())->method('get');
        $client->expects(self::once())->method('post');

        $event = $this->reminderEvent(null);
        $handler = $this->reminderHandler($client, $this->createMock(StateManager::class));

        self::assertSame([1 => true], $handler->handle([$event]));
    }

    private function reminderHandler(SmailyClient $client, StateManager $cartState): AutomationHandler
    {
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->method('forStore')->willReturn($client);
        $router = $this->createMock(Router::class);
        $router->method('resolve')->willReturn(new WorkflowMatch(7));

        return new AutomationHandler(
            $clientProvider,
            $router,
            $this->eventQueue,
            $this->createMock(Logger::class),
            $this->createMock(AccountResolver::class),
            $this->createMock(Config::class),
            $this->createMock(WebsiteOverrides::class),
            $this->createMock(StoreManagerInterface::class),
            $cartState,
            $this->noDiscount()
        );
    }

    /**
     * An abandoned-cart reminder of cart 42 — or, with $newContact null, a
     * welcome; the queue mock decodes it.
     */
    private function reminderEvent(?bool $newContact): Event
    {
        $payload = [
            'trigger_type' => $newContact === null ? 'welcome' : 'abandoned_cart',
            'store_id' => 1,
            'website_id' => 1,
            'address' => ['email' => 'person@example.com'],
        ];
        if ($newContact !== null) {
            $payload[AutomationHandler::CART_REMINDER] = ['quote_id' => 42, 'new_contact' => $newContact];
        }
        $this->eventQueue = $this->createMock(EventQueue::class);
        $this->eventQueue->method('decodePayload')->willReturn($payload);
        $event = $this->createMock(Event::class);
        $event->method('getId')->willReturn(1);

        return $event;
    }

    /**
     * An automation without a discount code: the address goes as queued.
     */
    private function noDiscount(): AutomationDiscount
    {
        $discount = $this->createMock(AutomationDiscount::class);
        $discount->method('address')->willReturnCallback(
            static fn (Event $event, array $payload): array => $payload['address']
        );

        return $discount;
    }
}
