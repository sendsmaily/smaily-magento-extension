<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Cron;

use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Model\ResourceModel\Db\VersionControl\Snapshot;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Quote\Model\ResourceModel\Quote\Collection as QuoteCollection;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Cron\AbandonedCart;
use Smaily\Connect\Model\AbandonedCart\PayloadBuilder;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Model\Log\QueueRowLoader;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * The abandoned-cart scan against the real cart tracker, the real queue and
 * Magento's own quote collection on the quote and quote_address mirrors.
 * PRO-3693: one address gets at most one reminder in 24 hours, whatever
 * number of carts carry it. PRO-3711: handled carts never keep a new one
 * out of the scan's page. The payload builder is stubbed: it reads the
 * cart's own email.
 */
class AbandonedCartTest extends IntegrationTestCase
{
    private const CART_TABLE = 'smaily_abandoned_cart';

    private StateManager $stateManager;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Support/Stub/QuoteCollectionFactory.php';
        $this->stateManager = $this->objectManager->create(StateManager::class);
        $schema = new SchemaInstaller($this->connection);
        $schema->createQuote();
        $schema->createQuoteAddress();
    }

    protected function tearDown(): void
    {
        $this->connection->query('DROP TABLE IF EXISTS `quote_address`');
        $this->connection->query('DROP TABLE IF EXISTS `quote`');
        parent::tearDown();
    }

    public function testASecondCartOfARemindedAddressIsSkippedAndTheLogSaysWhy(): void
    {
        $this->stateManager->markMailed(1, 1, 'shopper@example.com');
        $this->clock->travel(3 * 3600);

        $this->idleCarts([2 => 'Shopper@Example.com', 3 => 'other@example.com']);
        $this->cron()->execute();

        self::assertSame(StateManager::STATUS_SKIPPED, $this->statusOf(2), 'Compared in any case');
        self::assertNull($this->fetchRow(self::CART_TABLE, 2, 'quote_id')['mail_sent_at']);
        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(3));

        $rows = $this->queueRowsByEntity();
        self::assertSame(Event::STATUS_PENDING, $rows['other@example.com']['status']);
        $skipped = $rows['Shopper@Example.com'];
        self::assertSame(Event::STATUS_SENT, $skipped['status']);
        self::assertNull($skipped['sent_payload'], 'Nothing was sent');
        self::assertSame(AbandonedCart::SKIPPED_RECENTLY_REMINDED, $skipped['last_error']);
        self::assertSame(
            Collection::STATUS_SKIPPED,
            $this->objectManager->create(QueueRowLoader::class)->load('smaily-' . $skipped['id'])['status'] ?? null,
            'The Log shows the row as Skipped'
        );
        $claimed = $this->objectManager->create(EventQueue::class)->claimBatch();
        self::assertSame(
            ['other@example.com'],
            array_map(fn (Event $event): string => (string)$event->getData('entity_id'), $claimed),
            'The flusher never sends the skipped row'
        );
    }

    public function testTwoCartsOfOneAddressInOneRunGetOneReminder(): void
    {
        $this->idleCarts([5 => 'twice@example.com', 6 => 'TWICE@example.com']);
        $this->cron()->execute();

        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(5));
        self::assertSame(StateManager::STATUS_SKIPPED, $this->statusOf(6));
    }

    public function testAReminderOlderThan24HoursDoesNotHoldTheNextOneBack(): void
    {
        $this->stateManager->markMailed(1, 1, 'shopper@example.com');
        $this->clock->travel(86400 + 60);

        $this->idleCarts([2 => 'shopper@example.com']);
        $this->cron()->execute();

        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(2));
    }

    public function testASkippedCartIsNotWeighedAgainAndDoesNotCountAsAReminder(): void
    {
        $this->stateManager->markMailed(1, 1, 'shopper@example.com');
        $this->clock->travel(3600);
        $this->idleCarts([2 => 'shopper@example.com']);
        $this->cron()->execute();
        $this->cron()->execute();

        self::assertCount(1, $this->fetchAll(EventResource::TABLE_NAME), 'The skip is logged once');

        // The first reminder ages out; the skip did not start a new 24 hours.
        $this->clock->travel(86400);
        $this->idleCarts([2 => 'shopper@example.com', 3 => 'shopper@example.com']);
        $this->cron()->execute();

        self::assertSame(StateManager::STATUS_SKIPPED, $this->statusOf(2), 'A skipped cart stays skipped');
        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(3));
    }

    /**
     * PRO-3711: the scan reads one page of 100 carts. Handled carts stay
     * active and idle, so with more of them than a page holds a new cart was
     * never loaded. Each terminal status counts as handled; an open row (the
     * checkout opt-in) does not.
     */
    public function testANewCartIsRemindedWhenMoreHandledCartsThanOnePageHoldsAreIdle(): void
    {
        $statuses = [
            StateManager::STATUS_MAILED,
            StateManager::STATUS_SKIPPED,
            StateManager::STATUS_COMPLETED,
            StateManager::STATUS_ERASED,
            StateManager::STATUS_EXPIRED,
        ];
        $handled = [];
        $tracked = [];
        for ($quoteId = 1; $quoteId <= 150; $quoteId++) {
            $handled[$quoteId] = 'handled-' . $quoteId . '@example.com';
            $tracked[] = [
                'quote_id' => $quoteId,
                'store_id' => 1,
                'email' => $handled[$quoteId],
                'status' => $statuses[$quoteId % count($statuses)],
            ];
        }
        $this->idleCarts($handled);
        $this->connection->insertMultiple(self::CART_TABLE, $tracked);
        $this->idleCarts([151 => 'new@example.com', 152 => 'opted-in@example.com']);
        $this->stateManager->setNewsletterOptin(152, 1, 'opted-in@example.com', true);

        $this->cron()->execute();

        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(151), 'The new cart is reminded');
        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(152), 'An open row is not handled');
        self::assertSame(
            ['new@example.com', 'opted-in@example.com'],
            array_keys($this->queueRowsByEntity()),
            'No handled cart is weighed again'
        );
    }

    /**
     * PRO-3730: the page holds the oldest carts by id, whatever order they
     * were last changed in — a newer cart changed earlier waits for the next
     * run, as before the scan stopped walking the whole cart table.
     */
    public function testThePageHoldsTheOldestCartsByIdWhateverOrderTheyChangedIn(): void
    {
        $rows = [];
        for ($quoteId = 1; $quoteId <= 101; $quoteId++) {
            $rows[] = [
                'entity_id' => $quoteId,
                'store_id' => 1,
                'is_active' => 1,
                'items_count' => 1,
                'customer_email' => 'cart-' . $quoteId . '@example.com',
                'updated_at' => $this->clockDate(-3600 - $quoteId * 60),
            ];
        }
        $this->connection->insertMultiple('quote', $rows);

        $this->cron()->execute();

        self::assertSame(
            range(1, 100),
            array_map('intval', array_column($this->fetchAll(self::CART_TABLE, 'quote_id'), 'quote_id'))
        );
    }

    private function cron(): AbandonedCart
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $website = $this->createMock(Website::class);
        $website->method('getId')->willReturn(1);
        $website->method('getStoreIds')->willReturn([1]);
        $website->method('getDefaultStore')->willReturn($store);
        $storeView = $this->createMock(StoreInterface::class);
        $storeView->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn([$website]);
        $storeManager->method('getStore')->willReturn($storeView);

        $config = $this->createMock(Config::class);
        $config->method('isAbandonedCartEnabled')->willReturn(true);
        $config->method('isConnected')->willReturn(true);
        $config->method('getAbandonedCutoffMinutes')->willReturn(30);

        $languages = $this->createMock(LanguageResolver::class);
        $languages->method('forStore')->willReturn('en');
        $dispatcher = new SyncDispatcher(
            $this->createMock(SubscriberPayloadBuilder::class),
            $languages,
            $storeManager,
            $this->objectManager->create(EventQueue::class)
        );

        $payloadBuilder = $this->createMock(PayloadBuilder::class);
        $payloadBuilder->method('build')->willReturnCallback(
            fn (Quote $quote): array => ['email' => (string)$quote->getData('customer_email')]
        );

        return new AbandonedCart(
            $storeManager,
            $config,
            $this->quoteCollectionFactory(),
            $this->stateManager,
            $payloadBuilder,
            $dispatcher,
            $this->createMock(Emulation::class),
            $this->clock,
            $this->createMock(Logger::class)
        );
    }

    /**
     * Seed active carts with items, idle for an hour, each with its email on
     * the cart. A cart seeded again is idle for an hour again.
     *
     * @param array<int, string> $emailsByQuote
     */
    private function idleCarts(array $emailsByQuote): void
    {
        $rows = [];
        foreach ($emailsByQuote as $quoteId => $email) {
            $rows[] = [
                'entity_id' => $quoteId,
                'store_id' => 1,
                'is_active' => 1,
                'items_count' => 1,
                'customer_email' => $email,
                'updated_at' => $this->clockDate(-3600),
            ];
        }
        $this->connection->insertOnDuplicate('quote', $rows, ['customer_email', 'updated_at']);
    }

    /**
     * Magento's own quote collection on the mirrors — the scan's SQL runs as
     * in production. Only what needs the full application is stubbed: the
     * resource model (it names the table) and the quote objects (built
     * without their constructor, holding only the row).
     */
    private function quoteCollectionFactory(): QuoteCollectionFactory
    {
        $resource = $this->createMock(QuoteResource::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getMainTable')->willReturn('quote');
        $resource->method('getTable')->willReturnArgument(0);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        $entityFactory = $this->createMock(EntityFactoryInterface::class);
        $entityFactory->method('create')->willReturnCallback(function (): Quote {
            $quote = (new \ReflectionClass(Quote::class))->newInstanceWithoutConstructor();
            $quote->setIdFieldName('entity_id');

            return $quote;
        });

        $factory = $this->createMock(QuoteCollectionFactory::class);
        $factory->method('create')->willReturnCallback(
            fn (): QuoteCollection => $this->objectManager->create(QuoteCollection::class, [
                'entityFactory' => $entityFactory,
                'entitySnapshot' => $this->createMock(Snapshot::class),
                'resource' => $resource,
            ])
        );

        return $factory;
    }

    private function statusOf(int $quoteId): string
    {
        return (string)$this->fetchRow(self::CART_TABLE, $quoteId, 'quote_id')['status'];
    }

    /**
     * @return array<string, array<string, mixed>> queue rows by entity id
     */
    private function queueRowsByEntity(): array
    {
        return array_column($this->fetchAll(EventResource::TABLE_NAME), null, 'entity_id');
    }
}
