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
use Smaily\Connect\Model\Engine\MergeCustomerSender;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
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
        'merge_reason' => 'user_logged_in',
        'anon_session_id' => '0b7d3f7e-2c4f-4a8e-9d55-3f1d2e6c7a90',
    ];

    private Client&MockObject $client;
    private ProfilingConsent&MockObject $profilingConsent;
    private CustomerRepositoryInterface&MockObject $customerRepository;

    /** @var array<int, array{0: array<int|string, mixed>, 1: array<string, mixed>|null}> */
    private array $recorded = [];

    private MergeCustomerSender&MockObject $sender;
    private IngestQueue&MockObject $ingestQueue;

    /** @var string[] the engine calls in order: `customers`, `merge` */
    private array $calls = [];

    /** @var array<string, mixed> what the handler stored in the row's payload */
    private array $added = [];

    /** The row's last recorded answer, as the queue stores it. */
    private ?string $lastResponse = null;

    protected function setUp(): void
    {
        $this->recorded = [];
        $this->calls = [];
        $this->added = [];
        $this->lastResponse = null;
        $this->sender = $this->createMock(MergeCustomerSender::class);
        $this->ingestQueue = $this->createMock(IngestQueue::class);
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

    /**
     * CON-260: a row queued before named the login `login`, which the
     * engine's §7 enum refuses with 400; it goes with `user_logged_in`.
     */
    public function testARowQueuedWithTheOldLoginReasonIsSentWithTheOneTheEngineAccepts(): void
    {
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $this->client->expects(self::once())->method('identityMerge')->with(self::PAYLOAD)->willReturn(['ok' => true]);

        self::assertSame([1 => true], $this->handle(['merge_reason' => 'login'] + self::PAYLOAD));
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
     * CON-262 (contract v1.23.0 §4 group c, §7 "Send the customer first"):
     * a consenting login sends the customer, then the merge — in the same
     * run, so the cron order of the two queues does not matter.
     */
    public function testAConsentingLoginSendsTheCustomerBeforeTheMerge(): void
    {
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $this->engine();

        self::assertSame([1 => true], $this->handle(self::PAYLOAD + [IdentityMergeHandler::SEND_CUSTOMER => true]));
        self::assertSame(['customers', 'merge'], $this->calls);
        self::assertSame([], $this->added);
    }

    public function testALoginWithoutConsentSendsNoCustomer(): void
    {
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $this->sender->expects(self::never())->method('send');
        $this->engine(mergeFinds: [true]);

        self::assertSame([1 => true], $this->handle(self::PAYLOAD));
        self::assertSame(['merge'], $this->calls);
    }

    public function testAnOptedOutConsentingShopperIsNeitherSentNorMerged(): void
    {
        $this->profilingConsent->method('isAllowed')->willReturn(false);
        $this->sender->expects(self::never())->method('send');
        $this->client->expects(self::never())->method('identityMerge');

        self::assertEquals(
            [1 => new Skipped(IdentityMergeHandler::SKIPPED_OPTED_OUT)],
            $this->handle(self::PAYLOAD + [IdentityMergeHandler::SEND_CUSTOMER => true])
        );
    }

    /**
     * §7 "Retry after 404": a just-registered customer whose row is still
     * in the ingest queue is sent here, and the merge is tried once more.
     */
    public function testA404IsRetriedOnceAfterTheCustomerIsSent(): void
    {
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $this->queued(true);
        $this->engine();
        $this->client->method('lastExchange')->willReturn(['request' => self::PAYLOAD, 'response' => null]);

        self::assertSame([1 => true], $this->handle(self::PAYLOAD));
        self::assertSame(['merge', 'customers', 'merge'], $this->calls);
        self::assertSame([IdentityMergeHandler::CUSTOMER_NOT_FOUND => true], $this->added);
        self::assertCount(2, $this->recorded, 'Both merges are recorded on the row; the last one shows');
    }

    /**
     * The merge is not tried again before §4 answered 200: an outage takes
     * the ladder, and the row remembers the 404.
     */
    public function testNoRetryBeforeTheCustomerIsSent(): void
    {
        $outage = new EngineTransportException('Engine request failed with HTTP 503 after retries', 503);
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $this->queued(true);
        $this->engine(sendAnswers: [$outage]);

        self::assertSame([1 => $outage], $this->handle(self::PAYLOAD));
        self::assertSame(['merge', 'customers'], $this->calls);
        self::assertSame([IdentityMergeHandler::CUSTOMER_NOT_FOUND => true], $this->added);
    }

    /**
     * The next attempt of that row sends the customer before it merges, and
     * that merge is the one retry: a second 404 parks the row.
     */
    public function testTheNextAttemptAfterA404SendsTheCustomerFirstAndRetriesOnce(): void
    {
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $this->queued(true);
        $this->engine(mergeFinds: [false]);

        self::assertEquals(
            [1 => Failure::permanent(IdentityMergeHandler::NOT_FOUND_AFTER_SEND)],
            $this->handle(self::PAYLOAD + [IdentityMergeHandler::CUSTOMER_NOT_FOUND => true])
        );
        self::assertSame(['customers', 'merge'], $this->calls);
    }

    public function testAConsentingLoginWhoseMergeStillFindsNoCustomerIsRetriedOnceThenParked(): void
    {
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $this->sender->expects(self::once())->method('send');
        $this->engine(mergeFinds: [false, false]);

        self::assertEquals(
            [1 => Failure::permanent(IdentityMergeHandler::NOT_FOUND_AFTER_SEND)],
            $this->handle(self::PAYLOAD + [IdentityMergeHandler::SEND_CUSTOMER => true])
        );
        self::assertSame(['customers', 'merge', 'merge'], $this->calls);
    }

    /**
     * A customer the store does not send (no consent, not queued by the
     * live sync or an import): the merge is not retried, and the row says
     * why; Retry links the login once the customer is sent.
     */
    public function testA404ForACustomerTheStoreDoesNotSendIsParkedWithTheReason(): void
    {
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $this->queued(false);
        $this->sender->expects(self::never())->method('send');
        $this->engine();

        self::assertEquals(
            [1 => Failure::permanent(IdentityMergeHandler::NOT_SENT)],
            $this->handle(self::PAYLOAD)
        );
        self::assertSame(['merge'], $this->calls);
    }

    public function testACustomerTheEngineRefusesParksTheMergeWithTheEnginesReason(): void
    {
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $this->engine(refusal: 'email: Invalid email');

        self::assertEquals(
            [1 => Failure::permanent(
                'Campaign Intelligence refused the customer (email: Invalid email), so the login was not linked'
                . ' to them. Retry sends the customer and the login again.'
            )],
            $this->handle(self::PAYLOAD + [IdentityMergeHandler::SEND_CUSTOMER => true])
        );
        self::assertSame(['customers', 'merge'], $this->calls, 'No retry: the customer was not sent');
    }

    public function testAConsentingLoginWhoseAccountIsGoneIsStillMerged(): void
    {
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $this->engine(mergeFinds: [true], refusal: MergeCustomerSender::NO_ACCOUNT);

        self::assertSame([1 => true], $this->handle(self::PAYLOAD + [IdentityMergeHandler::SEND_CUSTOMER => true]));
    }

    /**
     * A row parked by a 404 before CON-262 carries no flag, only the
     * answer: its Retry sends the customer the store sends before it
     * merges.
     */
    public function testARowParkedByA404BeforeIsRetriedCustomerFirst(): void
    {
        $this->lastResponse = (string)json_encode([
            'http_status' => 404,
            'body' => ['error' => 'customer_not_found', 'message' => 'No customer found'],
        ]);
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $this->queued(true);
        $this->engine();

        self::assertSame([1 => true], $this->handle(self::PAYLOAD));
        self::assertSame(['customers', 'merge'], $this->calls);
    }

    /**
     * Such a row whose customer the store does not send links the login
     * once the customer reached the engine another way (Retry in the Log).
     */
    public function testARowParkedByA404BeforeLinksOnRetryOnceTheEngineHasTheCustomer(): void
    {
        $this->lastResponse = (string)json_encode([
            'http_status' => 404,
            'body' => ['error' => 'customer_not_found'],
        ]);
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $this->queued(false);
        $this->sender->expects(self::never())->method('send');
        $this->engine(mergeFinds: [true]);

        self::assertSame([1 => true], $this->handle(self::PAYLOAD));
    }

    public function testAnotherNotFoundIsAnOrdinaryRefusal(): void
    {
        $refusal = new EngineRequestException('Engine request failed with HTTP 404: Not found', 404, ['error' => 'x']);
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $this->sender->expects(self::never())->method('send');
        $this->ingestQueue->expects(self::never())->method('hasRow');
        $this->client->method('identityMerge')->willThrowException($refusal);

        self::assertSame([1 => $refusal], $this->handle(self::PAYLOAD));
    }

    /**
     * PRO-2466: the customer send that meets the account refusal leaves
     * the row waiting, unmerged.
     */
    public function testTheCustomerSendThatMeetsTheRefusalLeavesTheRowPending(): void
    {
        $this->profilingConsent->method('isAllowed')->willReturn(true);
        $refused = false;
        $this->sender->method('send')->willReturnCallback(static function () use (&$refused): ?string {
            $refused = true;
            throw new EngineRequestException('Engine request failed with HTTP 403: tenant_inactive', 403);
        });
        $this->client->expects(self::never())->method('identityMerge');

        self::assertEquals(
            [1 => new Pending()],
            $this->handle(self::PAYLOAD + [IdentityMergeHandler::SEND_CUSTOMER => true], $refused)
        );
    }

    /**
     * @param array<string, string|int|bool> $payload
     * @return array<int, mixed>
     */
    private function handle(array $payload, bool &$refused = false): array
    {
        $event = $this->createMock(Event::class);
        $event->method('getId')->willReturn(1);
        $event->method('getData')->willReturnCallback(
            fn (string $key = ''): mixed => $key === 'last_response' ? $this->lastResponse : null
        );
        $eventQueue = $this->createMock(EventQueue::class);
        $eventQueue->method('decodePayload')->willReturn($payload);
        $eventQueue->method('recordExchange')->willReturnCallback(
            function (Event $event, array $sent, ?array $response): void {
                $this->recorded[] = [$sent, $response];
            }
        );
        $eventQueue->method('addToPayload')->willReturnCallback(function (Event $event, array $values): void {
            $this->added = array_replace($this->added, $values);
        });

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
            $this->createMock(Logger::class),
            $this->sender,
            $this->ingestQueue
        );
    }

    /**
     * The engine as the handler meets it: the merge answers 404
     * `customer_not_found` until §4 took the customer (or $mergeAnswers
     * says otherwise, in order), and each call is logged in $calls.
     *
     * @param array<int, \Throwable|null> $sendAnswers per §4 call: null = took it, else thrown
     * @param array<int, bool> $mergeFinds per merge call: whether it finds the customer; default:
     *        found once §4 took the customer
     */
    private function engine(array $sendAnswers = [null], array $mergeFinds = [], ?string $refusal = null): void
    {
        $known = false;
        $this->sender->method('send')->with(42)->willReturnCallback(
            function () use (&$sendAnswers, &$known, $refusal): ?string {
                $this->calls[] = 'customers';
                $answer = array_shift($sendAnswers);
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }
                $known = $known || $refusal === null;

                return $refusal;
            }
        );
        $this->client->method('identityMerge')->willReturnCallback(
            function (array $payload) use (&$mergeFinds, &$known): array {
                $this->calls[] = 'merge';
                self::assertSame(self::PAYLOAD, $payload, 'Only the §7 fields go on the wire');
                $finds = $mergeFinds ? array_shift($mergeFinds) : $known;
                if (!$finds) {
                    throw self::notFound();
                }

                return ['ok' => true];
            }
        );
    }

    private static function notFound(): EngineRequestException
    {
        return new EngineRequestException(
            'Engine request failed with HTTP 404: No customer found with email person@example.com.',
            404,
            ['error' => 'customer_not_found', 'message' => 'No customer found with email person@example.com.']
        );
    }

    private function queued(bool $queued): void
    {
        $this->ingestQueue->method('hasRow')->with(Client::DOMAIN_CUSTOMERS, '42')->willReturn($queued);
    }
}
