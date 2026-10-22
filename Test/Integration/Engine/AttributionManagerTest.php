<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Engine;

use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\MarketingConsent;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\OrderPlacer;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * The attribution capture against the real side table, whose visitor token
 * and session id columns hold 64 characters. Values are synthetic.
 */
class AttributionManagerTest extends IntegrationTestCase
{
    private const ORDER_ID = 501;
    private const REC_ID = '3fa85f64-5717-4562-b3fc-2c963f66afa6';
    private const SESSION_ID = '0f8e3c1a-5b2d-4e6f-8a9b-1c2d3e4f5a6b';

    protected function tearDown(): void
    {
        // Magento's adapter connects with an empty SQL mode; put it back.
        $this->connection->query("SET SESSION sql_mode = ''");
        parent::tearDown();
    }

    /**
     * PRO-3584: under a strict SQL mode an over-long visitor token used to
     * fail the whole insert, and the order lost all four signals. It is
     * dropped on its own now; the order keeps the other three.
     */
    public function testAnOverLongVisitorTokenUnderAStrictSqlModeCostsTheOrderOnlyThatSignal(): void
    {
        $this->connection->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");

        $this->manager([
            'smaily_rec_id' => self::REC_ID,
            'smaily_rec_uid' => 'vt_' . str_repeat('a', 70),
            'smaily_rec_ctx' => 'welcome',
            'smaily_anon_sid' => self::SESSION_ID,
        ])->saveForOrder(self::ORDER_ID);

        self::assertSame([
            'rec_id' => self::REC_ID,
            'visitor_token' => null,
            'rec_ctx' => 'welcome',
            'anon_session_id' => self::SESSION_ID,
        ], $this->storedRow());
    }

    /**
     * PRO-3584: under Magento's own (empty) SQL mode an over-long session id
     * used to be cut to 64 characters and stored. It is not stored at all.
     */
    public function testAnOverLongSessionIdIsNotStoredCutShort(): void
    {
        $this->manager([
            'smaily_rec_uid' => 'vt_8f3k2a',
            'smaily_anon_sid' => str_repeat('b', 80),
        ])->saveForOrder(self::ORDER_ID);

        self::assertSame([
            'rec_id' => null,
            'visitor_token' => 'vt_8f3k2a',
            'rec_ctx' => null,
            'anon_session_id' => null,
        ], $this->storedRow());
    }

    /**
     * PRO-3584: well-formed values are stored as before.
     */
    public function testWellFormedValuesAreStoredAsBefore(): void
    {
        $this->connection->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");

        $this->manager([
            'smaily_rec_id' => self::REC_ID,
            'smaily_rec_uid' => 'vt_8f3k2a',
            'smaily_rec_ctx' => 'cart_abandoned',
            'smaily_anon_sid' => self::SESSION_ID,
        ])->saveForOrder(self::ORDER_ID);

        self::assertSame([
            'rec_id' => self::REC_ID,
            'visitor_token' => 'vt_8f3k2a',
            'rec_ctx' => 'cart_abandoned',
            'anon_session_id' => self::SESSION_ID,
        ], $this->storedRow());
    }

    /**
     * CON-181: a store-created token is stamped onto the order only with the
     * shopper's marketing consent; without it the order keeps its other
     * signals.
     */
    public function testAStoreCreatedTokenIsStampedOnlyWithMarketingConsent(): void
    {
        $cookies = [
            'smaily_rec_id' => self::REC_ID,
            'smaily_rec_uid' => 'vs_4fK9a2LmQ7xZ0bR3tY8wP1',
            'smaily_anon_sid' => self::SESSION_ID,
        ];

        $this->manager($cookies, false)->saveForOrder(self::ORDER_ID);
        self::assertSame([
            'rec_id' => self::REC_ID,
            'visitor_token' => null,
            'rec_ctx' => null,
            'anon_session_id' => self::SESSION_ID,
        ], $this->storedRow());

        $this->manager($cookies, true)->saveForOrder(self::ORDER_ID);
        self::assertSame([
            'rec_id' => self::REC_ID,
            'visitor_token' => 'vs_4fK9a2LmQ7xZ0bR3tY8wP1',
            'rec_ctx' => null,
            'anon_session_id' => self::SESSION_ID,
        ], $this->storedRow());
    }

    /**
     * @param array<string, string> $cookies by cookie name
     */
    private function manager(array $cookies, bool $marketingConsent = false): AttributionManager
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);
        $settings->method('getEngineConfig')->willReturn([]);

        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturnCallback(
            static fn (string $name) => $cookies[$name] ?? null
        );
        $consent = $this->createMock(MarketingConsent::class);
        $consent->method('isGiven')->willReturn($marketingConsent);

        return $this->objectManager->create(AttributionManager::class, [
            'settings' => $settings,
            'cookieManager' => $cookieManager,
            'orderPlacer' => $this->objectManager->create(OrderPlacer::class, [
                // Magento_LoginAsCustomer is absent here.
                'moduleManager' => $this->createMock(ModuleManager::class),
            ]),
            'marketingConsent' => $consent,
        ]);
    }

    /**
     * @return array<string, ?string>|false
     */
    private function storedRow(): array|false
    {
        return $this->connection->fetchRow(
            $this->connection->select()
                ->from('smaily_order_attribution', ['rec_id', 'visitor_token', 'rec_ctx', 'anon_session_id'])
                ->where('order_id = ?', self::ORDER_ID)
        );
    }
}
