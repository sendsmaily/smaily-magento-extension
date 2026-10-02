<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\AbandonedCart;

use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * The tracker's own gate against the real table. Contacts are synthetic.
 */
class StateManagerTest extends IntegrationTestCase
{
    private const SUBJECT = 'cart-state-1@example.test';

    private StateManager $stateManager;

    private SchemaInstaller $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateManager = $this->objectManager->create(StateManager::class);
        $this->schema = new SchemaInstaller($this->connection);
        $this->schema->createQuote();
    }

    protected function tearDown(): void
    {
        $this->connection->query('DROP TABLE IF EXISTS `quote`');
        parent::tearDown();
    }

    /**
     * PRO-2467: the erasure leaves a tombstone because the module may not
     * touch the core `quote` table. The cron's gate must still report that
     * quote as handled — otherwise a quote that is still active and idle past
     * the cutoff looks untracked to the next sweep and is mailed to the
     * address just erased.
     */
    public function testATombstonedQuoteStaysOutOfTheCronsCandidateSet(): void
    {
        $this->schema->seedQuote(11, ['is_active' => 1, 'items_count' => 1]);
        $this->stateManager->markMailed(11, 1, self::SUBJECT);
        self::assertSame(1, $this->stateManager->anonymizeForEmail(self::SUBJECT));

        self::assertSame(
            [],
            $this->passTheCronsGate([11]),
            'The tombstoned quote never reaches markMailed()/dispatchAutomation() again'
        );
    }

    /**
     * PRO-2469, edge 1: a tombstoned quote that later converts keeps the
     * tombstone — the completion write must not resurrect the row as
     * `completed`, which would say the extension still holds a record of
     * this shopper's cart.
     */
    public function testAConvertedTombstoneKeepsItsErasedStatusAndEmptyEmail(): void
    {
        $this->stateManager->markMailed(21, 1, self::SUBJECT);
        $this->stateManager->anonymizeForEmail(self::SUBJECT);

        $this->stateManager->markCompleted(21);

        $row = $this->fetchRow('smaily_abandoned_cart', 21, 'quote_id');
        self::assertSame(StateManager::STATUS_ERASED, $row['status']);
        self::assertNull($row['email']);
    }

    /**
     * PRO-2469, edge 2: the shopper types their address into checkout again
     * and ticks the newsletter box. That is their own fresh input, so it may
     * land on the row — but the row stays terminal, so the cart cron still
     * never mails it.
     */
    public function testAFreshCheckoutOptinRepopulatesTheTombstoneWithoutRevivingIt(): void
    {
        $this->schema->seedQuote(22, ['is_active' => 1, 'items_count' => 1]);
        $this->stateManager->markMailed(22, 1, self::SUBJECT);
        $this->stateManager->anonymizeForEmail(self::SUBJECT);

        $this->stateManager->setNewsletterOptin(22, 1, 'cart-state-1-again@example.test', true);

        $row = $this->fetchRow('smaily_abandoned_cart', 22, 'quote_id');
        self::assertSame('cart-state-1-again@example.test', $row['email']);
        self::assertSame(StateManager::STATUS_ERASED, $row['status']);
        self::assertSame('1', (string)$row['newsletter_optin']);
        self::assertSame(
            [],
            $this->passTheCronsGate([22]),
            'A repopulated tombstone is still terminal, so no reminder is scheduled'
        );
    }

    /**
     * An abandoned-cart restore link from before links carried their issue
     * moment is dated by the moment the reminder was sent.
     */
    public function testTheMomentAReminderWasSentIsReadBackOnlyForAMailedQuote(): void
    {
        $this->stateManager->markMailed(31, 1, self::SUBJECT);
        $this->stateManager->setNewsletterOptin(32, 1, self::SUBJECT, true);

        $sentAt = $this->stateManager->mailSentAt(31);

        self::assertNotNull($sentAt);
        self::assertEqualsWithDelta(time(), strtotime($sentAt . ' UTC'), 60);
        self::assertNull($this->stateManager->mailSentAt(32), 'A tracked quote that was never mailed');
        self::assertNull($this->stateManager->mailSentAt(33), 'An untracked quote');
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
