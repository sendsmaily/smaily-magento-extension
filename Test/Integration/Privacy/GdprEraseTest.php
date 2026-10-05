<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Privacy;

use Magento\Framework\Lock\LockManagerInterface;
use Smaily\Connect\Console\Command\GdprCommand;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Privacy\Erasure;
use Smaily\Connect\Model\Privacy\LocalEraser;
use Smaily\Connect\Model\Privacy\ProfilingOptOuts;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The Art. 17 erasure against the real tables (PRO-2452): what it does to
 * the two queues and the abandoned-cart tracker, and that a failing engine
 * never costs the local half.
 *
 * Contacts are synthetic throughout.
 */
class GdprEraseTest extends IntegrationTestCase
{
    private const SUBJECT = 'erase-test-1@example.test';
    private const BYSTANDER = 'erase-test-2@example.test';
    private const NON_ASCII_SUBJECT = 'mõni@näide.test';

    private const CART_TABLE = 'smaily_abandoned_cart';

    /** The merchant-facing labels the eraser keys its results by. */
    private const QUEUE_LABEL = 'Queued messages';
    private const ENGINE_LABEL = 'Engine queue';
    private const CART_LABEL = 'Abandoned carts';

    private EventQueue $eventQueue;
    private IngestQueue $ingestQueue;
    private StateManager $stateManager;
    private LocalEraser $eraser;
    private ProfilingOptOuts $optOuts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventQueue = $this->objectManager->create(EventQueue::class);
        $this->ingestQueue = $this->objectManager->create(IngestQueue::class);
        $this->stateManager = $this->objectManager->create(StateManager::class);
        $this->optOuts = $this->objectManager->create(ProfilingOptOuts::class, [
            'lockManager' => $this->createMock(LockManagerInterface::class),
        ]);
        $this->eraser = $this->objectManager->create(LocalEraser::class, ['optOuts' => $this->optOuts]);

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

    public function testErasureDeletesSendableRowsAndAnonymisesTheRest(): void
    {
        $this->seedContactSync(self::SUBJECT, 'sub-pending');
        $this->seedContactSync(self::SUBJECT, 'sub-sending');
        $this->seedContactSync(self::SUBJECT, 'sub-sent');
        $this->seedContactSync(self::BYSTANDER, 'other-pending');
        $this->markRow(EventResource::TABLE_NAME, 'sub-sending', ['status' => Event::STATUS_SENDING]);
        $this->markRow(EventResource::TABLE_NAME, 'sub-sent', [
            'status' => Event::STATUS_SENT,
            'sent_payload' => (string)json_encode([
                'email' => self::SUBJECT,
                'first_name' => 'Test',
                'last_name' => 'Erasure',
            ]),
            'last_response' => (string)json_encode(['message' => 'queued for ' . self::SUBJECT]),
        ]);

        $this->ingestQueue->enqueue('customers', ['email' => self::SUBJECT], '77', null, 'ing-pending');
        $this->ingestQueue->enqueue('orders', ['customer' => ['email' => self::SUBJECT]], '100000001', null, 'ing-sent');
        $this->markRow(IngestEventResource::TABLE_NAME, 'ing-sent', [
            'status' => IngestEvent::STATUS_FAILED,
            'last_error' => 'rejected recipient ' . self::SUBJECT,
        ]);

        $this->stateManager->markMailed(11, 1, self::SUBJECT);
        $this->stateManager->markMailed(12, 1, self::BYSTANDER);

        self::assertSame(
            [
                self::QUEUE_LABEL => ['removed' => 2, 'anonymised' => 1],
                self::ENGINE_LABEL => ['removed' => 1, 'anonymised' => 1],
                self::CART_LABEL => ['removed' => 0, 'anonymised' => 1],
            ],
            $this->eraser->erase(self::SUBJECT),
            'Sendable rows go, terminal rows are kept anonymised'
        );

        $events = array_column($this->fetchAll(EventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(['sub-sent', 'other-pending'], array_keys($events), 'Sendable rows are gone, the rest stays');

        $erased = $events['sub-sent'];
        self::assertSame(Erasure::PLACEHOLDER, $erased['entity_id']);
        self::assertSame(EventType::CONTACT_SYNC, $erased['event_type'], 'The merchant keeps the record of the send');
        self::assertSame(Event::STATUS_SENT, $erased['status']);
        foreach (['payload', 'sent_payload', 'last_response'] as $column) {
            $blob = (string)$erased[$column];
            self::assertJson($blob, $column . ' stays valid JSON');
            self::assertStringNotContainsString(self::SUBJECT, $blob);
            self::assertStringNotContainsString('Erasure', $blob, 'The name goes with the address');
        }

        $ingest = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(['ing-sent'], array_keys($ingest));
        self::assertSame(Erasure::PLACEHOLDER, $ingest['ing-sent']['entity_id']);
        self::assertSame(Erasure::PLACEHOLDER, $ingest['ing-sent']['last_error']);
        self::assertStringNotContainsString(self::SUBJECT, (string)$ingest['ing-sent']['payload']);

        self::assertSame([], $this->stateManager->rowsForEmail(self::SUBJECT), 'The address is gone');
        $cartStatuses = array_column($this->fetchAll(self::CART_TABLE), 'status', 'quote_id');
        self::assertSame(
            StateManager::STATUS_ERASED,
            $cartStatuses[11] ?? null,
            'The cart row is kept as an email-less tombstone, never deleted'
        );
        $carts = $this->stateManager->rowsForEmail(self::BYSTANDER);
        self::assertCount(1, $carts, 'Only the subject\'s cart row is tombstoned');
        self::assertSame('12', (string)$carts[0]['quote_id']);

        // Another contact's pending row is untouched, and a second run is a no-op.
        self::assertSame(Event::STATUS_PENDING, $events['other-pending']['status']);
        self::assertSame(
            [
                self::QUEUE_LABEL => ['removed' => 0, 'anonymised' => 0],
                self::ENGINE_LABEL => ['removed' => 0, 'anonymised' => 0],
                self::CART_LABEL => ['removed' => 0, 'anonymised' => 0],
            ],
            $this->eraser->erase(self::SUBJECT)
        );
    }

    /**
     * PRO-3693: an active cart that holds the address but that the tracker
     * has not seen yet — idle less than the cutoff, or a guest's typed email —
     * is never mailed after the erasure: the cron's own gate skips it. The
     * core quote rows are not changed.
     */
    public function testAnActiveCartTheTrackerHasNotSeenIsNeverMailedAfterTheErasure(): void
    {
        $schema = new SchemaInstaller($this->connection);
        // On the cart itself, in another case.
        $schema->seedQuote(21, ['is_active' => 1, 'items_count' => 1, 'customer_email' => 'Erase-Test-1@Example.test']);
        // Only on the billing address.
        $schema->seedQuote(22, ['is_active' => 1, 'items_count' => 1, 'store_id' => 2]);
        $this->connection->insert('quote_address', [
            'quote_id' => 22,
            'address_type' => 'billing',
            'email' => self::SUBJECT,
        ]);
        // Tracked under another address (the checkout opt-in), the subject's on the cart.
        $schema->seedQuote(23, ['is_active' => 1, 'items_count' => 1, 'customer_email' => self::SUBJECT]);
        $this->stateManager->setNewsletterOptin(23, 1, 'earlier@example.test', true);
        // Ordered already: an inactive cart is never mailed.
        $schema->seedQuote(24, ['is_active' => 0, 'items_count' => 1, 'customer_email' => self::SUBJECT]);
        // Someone else's.
        $schema->seedQuote(25, ['is_active' => 1, 'items_count' => 1, 'customer_email' => self::BYSTANDER]);
        $quotesBefore = $this->fetchAll('quote', 'entity_id');

        self::assertSame([21, 22, 23, 24, 25], $this->passTheCronsGate([21, 22, 23, 24, 25]));

        self::assertSame(
            ['removed' => 0, 'anonymised' => 3],
            $this->eraser->erase(self::SUBJECT)[self::CART_LABEL]
        );

        self::assertSame(
            [24, 25],
            $this->passTheCronsGate([21, 22, 23, 24, 25]),
            'The scan skips every active cart that holds the erased address'
        );
        $rows = array_column($this->fetchAll(self::CART_TABLE, 'quote_id'), null, 'quote_id');
        self::assertSame(['21', '22', '23'], array_map('strval', array_keys($rows)));
        foreach ($rows as $row) {
            self::assertSame(StateManager::STATUS_ERASED, $row['status']);
            self::assertNull($row['email']);
        }
        self::assertSame('2', (string)$rows[22]['store_id']);
        self::assertSame($quotesBefore, $this->fetchAll('quote', 'entity_id'), 'The core quote rows are not changed');

        // A second run finds nothing new.
        self::assertSame(
            ['removed' => 0, 'anonymised' => 0],
            $this->eraser->erase(self::SUBJECT)[self::CART_LABEL]
        );
    }

    /**
     * PRO-2448's edge, closed here: the address is JSON-escaped in the
     * stored bytes, so only a decoding match finds the row.
     */
    public function testANonAsciiAddressIsStillMatchedAndAnonymised(): void
    {
        $this->eventQueue->enqueue(
            EventType::AUTOMATION_TRIGGER,
            ['address' => ['email' => self::NON_ASCII_SUBJECT, 'first_name' => 'Tõnu']],
            null,
            0,
            'non-ascii'
        );
        $this->markRow(EventResource::TABLE_NAME, 'non-ascii', ['status' => Event::STATUS_SENT]);

        $stored = (string)$this->fetchAll(EventResource::TABLE_NAME)[0]['payload'];
        self::assertStringNotContainsString(self::NON_ASCII_SUBJECT, $stored, 'The address is escaped on disk');

        $counts = $this->eraser->erase(self::NON_ASCII_SUBJECT);

        self::assertSame(['removed' => 0, 'anonymised' => 1], $counts[self::QUEUE_LABEL]);
        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Erasure::PLACEHOLDER, $row['entity_id']);
        self::assertSame(
            ['address' => ['email' => '[erased]', 'first_name' => '[erased]']],
            json_decode((string)$row['payload'], true)
        );
    }

    /**
     * The command's own job: the summary a merchant reads, in plain labels.
     */
    public function testTheCommandSummarisesTheErasureInPlainLabels(): void
    {
        $this->seedContactSync(self::SUBJECT, 'cli-pending');
        $this->seedContactSync(self::SUBJECT, 'cli-sent');
        $this->markRow(EventResource::TABLE_NAME, 'cli-sent', ['status' => Event::STATUS_SENT]);
        $this->stateManager->markMailed(11, 1, self::SUBJECT);

        $tester = $this->runCommand(['action' => 'erase', 'email' => self::SUBJECT, '--force' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Queued messages: 1 removed, 1 anonymized', $tester->getDisplay());
        self::assertStringContainsString('Engine queue: 0 removed, 0 anonymized', $tester->getDisplay());
        self::assertStringContainsString('Abandoned carts: 0 removed, 1 anonymized', $tester->getDisplay());
    }

    public function testAFailingEngineStillLeavesTheLocalHalfErased(): void
    {
        $this->seedContactSync(self::SUBJECT, 'engine-down');

        $client = $this->createMock(Client::class);
        $client->method('customerDelete')->willThrowException(new EngineTransportException('HTTP 503'));
        $tester = $this->runCommand(
            ['action' => 'erase', 'email' => self::SUBJECT, '--force' => true],
            $client
        );

        self::assertSame(1, $tester->getStatusCode(), 'The merchant must know to retry the engine part');
        self::assertStringContainsString('HTTP 503', $tester->getDisplay());
        self::assertStringContainsString('Queued messages: 1 removed', $tester->getDisplay());
        self::assertSame([], $this->fetchAll(EventResource::TABLE_NAME));
    }

    public function testExportListsTheSameRowsTheErasureWouldTake(): void
    {
        $this->seedContactSync(self::SUBJECT, 'exp-1');
        $this->ingestQueue->enqueue('customers', ['email' => self::SUBJECT], '77', null, 'exp-2');
        $this->stateManager->markMailed(11, 1, self::SUBJECT);
        $this->seedContactSync(self::BYSTANDER, 'exp-other');

        $client = $this->createMock(Client::class);
        $client->method('customerExport')->willReturn(['customer' => ['orders' => 3]]);
        $tester = $this->runCommand(['action' => 'export', 'email' => self::SUBJECT], $client);

        self::assertSame(0, $tester->getStatusCode());
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertSame(['customer' => ['orders' => 3]], $decoded['engine']);
        self::assertSame(
            [['type' => EventType::CONTACT_SYNC, 'status' => Event::STATUS_PENDING, 'created_at' => $this->createdAt('exp-1')]],
            $decoded['local'][self::QUEUE_LABEL],
            'One row, and not the other contact\'s'
        );
        self::assertCount(1, $decoded['local'][self::ENGINE_LABEL]);
        self::assertCount(1, $decoded['local'][self::CART_LABEL]);
    }

    /**
     * PRO-3765: a profiling-consent row's entity is the shopper's keyed hash
     * — a row queued before, the plain address. The erasure finds both
     * forms, by the entity as well as by the payload.
     */
    public function testErasureFindsTheProfilingConsentRowsByKeyedHashAndByThePlainAddress(): void
    {
        $optOuts = $this->optOuts;
        $key = $optOuts->addressKey(self::SUBJECT);
        $optOut = ['email' => self::SUBJECT, 'opt_out' => true, 'opted_out_at' => '2026-10-05T10:00:00Z'];
        $this->eventQueue->enqueue(EventType::ENGINE_PROFILING_CONSENT, $optOut, $key, 0, 'consent-pending');
        $this->eventQueue->enqueue(EventType::ENGINE_PROFILING_CONSENT, $optOut, $key, 0, 'consent-sent');
        $this->eventQueue->enqueue(EventType::ENGINE_PROFILING_CONSENT, $optOut, self::SUBJECT, 0, 'consent-legacy');
        // Only the entity names the shopper here.
        $this->eventQueue->enqueue(EventType::ENGINE_PROFILING_CONSENT, [], $key, 0, 'consent-entity-only');
        $this->eventQueue->enqueue(
            EventType::ENGINE_PROFILING_CONSENT,
            ['email' => self::BYSTANDER, 'opt_out' => true],
            $optOuts->addressKey(self::BYSTANDER),
            0,
            'consent-other'
        );
        $this->markRow(EventResource::TABLE_NAME, 'consent-sent', ['status' => Event::STATUS_SENT]);
        $this->markRow(EventResource::TABLE_NAME, 'consent-entity-only', ['status' => Event::STATUS_FAILED]);

        self::assertSame(
            ['removed' => 2, 'anonymised' => 2],
            $this->eraser->erase(self::SUBJECT)[self::QUEUE_LABEL]
        );

        $events = array_column($this->fetchAll(EventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(['consent-sent', 'consent-entity-only', 'consent-other'], array_keys($events));
        self::assertSame(Erasure::PLACEHOLDER, $events['consent-sent']['entity_id']);
        self::assertStringNotContainsString(self::SUBJECT, (string)$events['consent-sent']['payload']);
        self::assertSame(Erasure::PLACEHOLDER, $events['consent-entity-only']['entity_id']);
        self::assertSame($optOuts->addressKey(self::BYSTANDER), $events['consent-other']['entity_id']);
    }

    public function testAnAnonymisedRowIsNeverRevivedByRetry(): void
    {
        $this->seedContactSync(self::SUBJECT, 'retry-me');
        $this->markRow(EventResource::TABLE_NAME, 'retry-me', ['status' => Event::STATUS_FAILED]);
        $id = (int)$this->fetchAll(EventResource::TABLE_NAME)[0]['id'];

        $this->eraser->erase(self::SUBJECT);

        self::assertSame(0, $this->eventQueue->retry([$id]));
        self::assertSame(Event::STATUS_FAILED, $this->fetchRow(EventResource::TABLE_NAME, $id)['status']);
    }

    private function seedContactSync(string $email, string $uuid): void
    {
        $this->eventQueue->enqueue(
            EventType::CONTACT_SYNC,
            ['store_id' => 1, 'contact' => ['email' => $email, 'first_name' => 'Test', 'last_name' => 'Erasure']],
            $email,
            0,
            $uuid
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function markRow(string $table, string $uuid, array $values): void
    {
        $this->connection->update(
            $this->connection->getTableName($table),
            $values,
            ['event_uuid = ?' => $uuid]
        );
    }

    private function createdAt(string $uuid): string
    {
        return (string)$this->connection->fetchOne(
            $this->connection->select()
                ->from($this->connection->getTableName(EventResource::TABLE_NAME), ['created_at'])
                ->where('event_uuid = ?', $uuid)
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runCommand(array $arguments, ?Client $client = null): CommandTester
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);

        if ($client === null) {
            $client = $this->createMock(Client::class);
            $client->method('customerDelete')->willReturn(['ok' => true]);
        }

        $command = new GdprCommand($settings, $client, $this->eraser);

        $tester = new CommandTester($command);
        $tester->execute($arguments);

        return $tester;
    }

    /**
     * The quotes among $quoteIds that the cron's gate lets through.
     *
     * @param int[] $quoteIds
     * @return int[]
     */
    private function passTheCronsGate(array $quoteIds): array
    {
        $select = $this->connection->select()
            ->from(['main_table' => 'quote'], ['entity_id'])
            ->where('main_table.entity_id IN (?)', $quoteIds)
            ->order('main_table.entity_id ASC');
        $this->stateManager->excludeHandled($select, 'main_table.entity_id');

        return array_map('intval', $this->connection->fetchCol($select));
    }
}
