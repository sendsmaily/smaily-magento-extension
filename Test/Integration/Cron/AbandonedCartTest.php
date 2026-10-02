<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Cron;

use Magento\Framework\DB\Select;
use Magento\Quote\Model\Quote;
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

/**
 * PRO-3693: one address gets at most one abandoned-cart reminder in 24
 * hours, whatever number of carts carry it — against the real cart tracker
 * and the real queue. Magento's quote collection and the payload builder are
 * stubbed: each test hands the cron the idle carts and their addresses.
 */
class AbandonedCartTest extends IntegrationTestCase
{
    private const CART_TABLE = 'smaily_abandoned_cart';

    private StateManager $stateManager;

    /**
     * @var array<int, string> the address each idle cart resolves to
     */
    private array $idleCarts = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Support/Stub/QuoteCollectionFactory.php';
        $this->stateManager = $this->objectManager->create(StateManager::class);
    }

    public function testASecondCartOfARemindedAddressIsSkippedAndTheLogSaysWhy(): void
    {
        $this->stateManager->markMailed(1, 1, 'shopper@example.com');
        $this->clock->travel(3 * 3600);

        $this->idleCarts = [2 => 'Shopper@Example.com', 3 => 'other@example.com'];
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
        $this->idleCarts = [5 => 'twice@example.com', 6 => 'TWICE@example.com'];
        $this->cron()->execute();

        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(5));
        self::assertSame(StateManager::STATUS_SKIPPED, $this->statusOf(6));
    }

    public function testAReminderOlderThan24HoursDoesNotHoldTheNextOneBack(): void
    {
        $this->stateManager->markMailed(1, 1, 'shopper@example.com');
        $this->clock->travel(86400 + 60);

        $this->idleCarts = [2 => 'shopper@example.com'];
        $this->cron()->execute();

        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(2));
    }

    public function testASkippedCartIsNotWeighedAgainAndDoesNotCountAsAReminder(): void
    {
        $this->stateManager->markMailed(1, 1, 'shopper@example.com');
        $this->clock->travel(3600);
        $this->idleCarts = [2 => 'shopper@example.com'];
        $this->cron()->execute();
        $this->cron()->execute();

        self::assertCount(1, $this->fetchAll(EventResource::TABLE_NAME), 'The skip is logged once');

        // The first reminder ages out; the skip did not start a new 24 hours.
        $this->clock->travel(86400);
        $this->idleCarts = [2 => 'shopper@example.com', 3 => 'shopper@example.com'];
        $this->cron()->execute();

        self::assertSame(StateManager::STATUS_SKIPPED, $this->statusOf(2), 'A skipped cart stays skipped');
        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(3));
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
            fn (Quote $quote): array => ['email' => $this->idleCarts[(int)$quote->getId()]]
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
     * A quote collection that holds the idle carts of the current test.
     */
    private function quoteCollectionFactory(): QuoteCollectionFactory
    {
        $select = $this->createMock(Select::class);
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $collection = $this->createMock(QuoteCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getConnection')->willReturn($this->connection);
        $collection->method('getTable')->willReturnArgument(0);
        $collection->method('getItems')->willReturnCallback(function (): array {
            $quotes = [];
            foreach (array_keys($this->idleCarts) as $quoteId) {
                $quote = $this->createMock(Quote::class);
                $quote->method('getId')->willReturn($quoteId);
                $quote->method('getStoreId')->willReturn(1);
                $quotes[$quoteId] = $quote;
            }

            return $quotes;
        });

        $factory = $this->createMock(QuoteCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

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
