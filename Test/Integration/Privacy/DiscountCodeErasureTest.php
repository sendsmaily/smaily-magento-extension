<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Privacy;

use Smaily\Connect\Console\Command\GdprCommand;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Privacy\LocalEraser;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The discount codes the extension made for a shopper in the data-subject
 * export and erasure (CON-225), on Magento's own coupon and order tables:
 * the export lists them, the erasure deletes an unused one and keeps a used
 * one with its order, and nobody else's code — another shopper's or one the
 * merchant made — is touched.
 *
 * Contacts are synthetic throughout.
 */
class DiscountCodeErasureTest extends IntegrationTestCase
{
    private const SUBJECT = 'erase-test-1@example.test';
    private const LABEL = 'Discount codes';

    private const RULE_ID = 5;

    private EventQueue $eventQueue;
    private LocalEraser $eraser;
    private SchemaInstaller $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = new SchemaInstaller($this->connection);
        $this->schema->createQuote();
        $this->schema->createQuoteAddress();
        $this->schema->createSalesOrder();
        $this->schema->createContactTables();
        $this->schema->createSalesRuleCouponTables();
        $this->eventQueue = $this->objectManager->create(EventQueue::class);
        $this->eraser = $this->objectManager->create(LocalEraser::class);
    }

    protected function tearDown(): void
    {
        $this->schema->dropSalesRuleCouponTables();
        $this->schema->dropContactTables();
        $this->schema->dropSalesOrder();
        $this->connection->query('DROP TABLE IF EXISTS `quote_address`');
        $this->connection->query('DROP TABLE IF EXISTS `quote`');
        parent::tearDown();
    }

    public function testTheExportListsTheShoppersCodes(): void
    {
        $this->seedShoppersCodes();

        $tester = $this->runCommand(['action' => 'export', 'email' => self::SUBJECT]);

        self::assertSame(0, $tester->getStatusCode());
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertSame(
            [
                ['code' => 'UNUSED1', 'created_at' => '2026-10-01 10:00:00', 'expires_at' => '2026-10-08', 'used' => false],
                ['code' => 'COUNTED1', 'created_at' => '2026-10-01 10:00:00', 'expires_at' => '2026-10-08', 'used' => true],
                ['code' => 'ORDERED1', 'created_at' => '2026-10-01 10:00:00', 'expires_at' => '2026-10-08', 'used' => true],
            ],
            $decoded['local'][self::LABEL],
            'The shopper\'s codes once each, and nobody else\'s'
        );
    }

    public function testTheErasureDeletesTheUnusedCodesAndKeepsTheUsedOnes(): void
    {
        $this->seedShoppersCodes();

        $tester = $this->runCommand(['action' => 'erase', 'email' => self::SUBJECT, '--force' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Discount codes: 1 removed, 2 anonymized', $tester->getDisplay());
        self::assertSame(
            ['COUNTED1', 'ORDERED1', 'ACCENT1', 'LONGER1', 'ADMIN1'],
            array_column($this->fetchAll('salesrule_coupon', 'coupon_id'), 'code'),
            'The unused code goes; the used ones, the other shoppers\' and the merchant\'s stay'
        );
        self::assertSame('ordered1', $this->fetchRow('sales_order', 1, 'entity_id')['coupon_code'], 'The order stays');

        // Nothing ties a code to the address any more: a second run and the
        // export find none.
        self::assertSame(['removed' => 0, 'anonymised' => 0], $this->eraser->erase(self::SUBJECT)[self::LABEL]);
        self::assertSame([], $this->eraser->export(self::SUBJECT)[self::LABEL]);
    }

    public function testAShopperWithoutCodesTouchesNoCode(): void
    {
        $this->seedShoppersCodes();

        self::assertSame(
            ['removed' => 0, 'anonymised' => 0],
            $this->eraser->erase('erase-test-3@example.test')[self::LABEL]
        );
        self::assertCount(6, $this->fetchAll('salesrule_coupon', 'coupon_id'));
    }

    /**
     * The subject's welcome email (an unused code, sent again from the Log
     * with the same code), first-order email (a code Magento counted as
     * used) and abandoned-cart email (a code an order carries while Magento
     * has not counted it yet). Beside them: a code of an address that
     * differs by an accent, one of an address that contains the subject's,
     * and a code the merchant generated in the admin that a row names.
     */
    private function seedShoppersCodes(): void
    {
        $this->seedCode('UNUSED1');
        $this->seedCode('COUNTED1', 1);
        $this->seedCode('ORDERED1');
        $this->seedCode('ACCENT1');
        $this->seedCode('LONGER1');
        $this->connection->insert('salesrule_coupon', [
            'rule_id' => self::RULE_ID,
            'code' => 'ADMIN1',
            'type' => 1,
            'expiration_date' => null,
            'created_at' => '2026-10-01 10:00:00',
        ]);
        $this->connection->insert('sales_order', [
            'entity_id' => 1,
            'increment_id' => '100000001',
            'store_id' => 1,
            'customer_is_guest' => 1,
            'customer_email' => self::SUBJECT,
            'coupon_code' => 'ordered1',
        ]);

        $this->seedAutomation(Trigger::WELCOME, 'Erase-Test-1@Example.test', 'UNUSED1', 'welcome', Event::STATUS_SENT);
        $this->seedAutomation(Trigger::WELCOME, self::SUBJECT, 'UNUSED1', 'welcome-again', Event::STATUS_FAILED);
        $this->seedAutomation(Trigger::FIRST_ORDER, self::SUBJECT, 'COUNTED1', 'first-order', Event::STATUS_SENT);
        $this->seedAutomation(Trigger::ABANDONED_CART, self::SUBJECT, 'ORDERED1', 'cart', Event::STATUS_SENT);
        $this->seedAutomation(Trigger::WELCOME, 'erase-tèst-1@example.test', 'ACCENT1', 'accent-shopper', Event::STATUS_SENT);
        $this->seedAutomation(Trigger::WELCOME, 'x' . self::SUBJECT, 'LONGER1', 'longer-shopper', Event::STATUS_SENT);
        $this->seedAutomation(Trigger::FIRST_ORDER, self::SUBJECT, 'ADMIN1', 'admin-code', Event::STATUS_SENT);
    }

    /**
     * A code as the extension makes it: auto-generated, single-use, its
     * last valid day written with the end-of-day time.
     */
    private function seedCode(string $code, int $timesUsed = 0): void
    {
        $this->connection->insert('salesrule_coupon', [
            'rule_id' => self::RULE_ID,
            'code' => $code,
            'usage_limit' => 1,
            'times_used' => $timesUsed,
            'type' => 1,
            'expiration_date' => '2026-10-08 23:59:59',
            'created_at' => '2026-10-01 10:00:00',
        ]);
    }

    private function seedAutomation(string $trigger, string $email, string $code, string $uuid, string $status): void
    {
        $this->eventQueue->enqueue(
            EventType::AUTOMATION_TRIGGER,
            [
                'trigger_type' => $trigger,
                'store_id' => 1,
                'website_id' => 1,
                'language' => 'en',
                'address' => [
                    'email' => $email,
                    Trigger::COUPON_FIELDS[$trigger][0] => $code,
                    Trigger::COUPON_FIELDS[$trigger][1] => '2026-10-08',
                ],
            ],
            strtolower($email),
            1,
            $uuid
        );
        $this->connection->update(
            $this->connection->getTableName(EventResource::TABLE_NAME),
            ['status' => $status],
            ['event_uuid = ?' => $uuid]
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runCommand(array $arguments): CommandTester
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(false);

        $tester = new CommandTester(new GdprCommand($settings, $this->createMock(Client::class), $this->eraser));
        $tester->execute($arguments);

        return $tester;
    }
}
