<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Migration;

use Smaily\Connect\Setup\Patch\Schema\MigrateLegacyQuoteColumns;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * The 2.8.x schema cleanup patch against a real quote table carrying the
 * legacy reminder_date/is_sent columns and the legacy smaily_customer_sync
 * table: mailed-state carry-over, column drops, and idempotent re-runs.
 */
class MigrateLegacyQuoteColumnsTest extends IntegrationTestCase
{
    private const STATE_TABLE = 'smaily_abandoned_cart';

    private SchemaInstaller $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema = new SchemaInstaller($this->connection);
        $this->schema->createQuote();
        $this->schema->addLegacyQuoteColumns();
        $this->connection->query(
            'CREATE TABLE IF NOT EXISTS `smaily_customer_sync` (`id` INT NOT NULL, PRIMARY KEY (`id`))'
        );
    }

    protected function tearDown(): void
    {
        $this->connection->query('DROP TABLE IF EXISTS `quote`');
        $this->connection->query('DROP TABLE IF EXISTS `smaily_customer_sync`');
        parent::tearDown();
    }

    public function testMailedQuotesAreCopiedAndLegacyArtifactsAreDropped(): void
    {
        $this->seedQuote(1, 'mailed@example.com', 1, '2026-01-05 10:00:00');
        $this->seedQuote(2, 'open@example.com', 0, null);
        $this->seedQuote(3, 'null-state@example.com', null, null);

        $this->applyPatch();

        $stateRows = $this->fetchAll(self::STATE_TABLE);
        self::assertCount(1, $stateRows, 'Only is_sent=1 quotes carry over');
        self::assertSame('1', (string)$stateRows[0]['quote_id']);
        self::assertSame('mailed', $stateRows[0]['status']);
        self::assertSame('mailed@example.com', $stateRows[0]['email']);
        self::assertSame('2026-01-05 10:00:00', $stateRows[0]['abandoned_at']);
        self::assertSame('2026-01-05 10:00:00', $stateRows[0]['mail_sent_at']);

        self::assertFalse($this->connection->tableColumnExists('quote', 'is_sent'));
        self::assertFalse($this->connection->tableColumnExists('quote', 'reminder_date'));
        self::assertFalse($this->connection->isTableExists('smaily_customer_sync'));
    }

    public function testExistingAbandonedCartStateIsNotClobbered(): void
    {
        $this->seedQuote(7, 'kept@example.com', 1, '2026-01-05 10:00:00');
        $this->connection->insert(self::STATE_TABLE, [
            'quote_id' => 7,
            'store_id' => 1,
            'email' => 'kept@example.com',
            'status' => 'completed',
        ]);

        $this->applyPatch();

        $stateRows = $this->fetchAll(self::STATE_TABLE);
        self::assertCount(1, $stateRows);
        self::assertSame('completed', $stateRows[0]['status'], 'INSERT IGNORE must keep the existing row');
    }

    public function testRerunOnCleanSchemaIsANoOp(): void
    {
        $this->seedQuote(1, 'mailed@example.com', 1, null);

        $this->applyPatch();
        $this->applyPatch();

        self::assertCount(1, $this->fetchAll(self::STATE_TABLE));
        self::assertFalse($this->connection->tableColumnExists('quote', 'is_sent'));
    }

    private function applyPatch(): void
    {
        /** @var MigrateLegacyQuoteColumns $patch */
        $patch = $this->objectManager->create(MigrateLegacyQuoteColumns::class);
        $patch->apply();
    }

    private function seedQuote(int $entityId, string $email, ?int $isSent, ?string $reminderDate): void
    {
        $this->schema->seedQuote($entityId, [
            'customer_email' => $email,
            'is_sent' => $isSent,
            'reminder_date' => $reminderDate,
        ]);
    }
}
