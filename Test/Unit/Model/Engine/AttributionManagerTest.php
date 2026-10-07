<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\OrderPlacer;

/**
 * The visitor-token cookie is read in either form the contract allows, an
 * engine `vt_` token or a store-created `vs_` token (PRO-3912); a value of
 * neither form reads as absent. An order an admin places with "Login as
 * Customer" (PRO-3925) or in the admin's order screen (PRO-3930) is stamped
 * with nothing. Values are synthetic.
 */
class AttributionManagerTest extends TestCase
{
    /**
     * @dataProvider visitorCookies
     */
    public function testTheVisitorTokenCookieIsReadInEitherForm(string $cookie, ?string $expected): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('getEngineConfig')->willReturn([]);
        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturnCallback(
            static fn (string $name) => $name === AttributionManager::DEFAULT_COOKIE_VISITOR ? $cookie : null
        );

        $manager = new AttributionManager(
            $settings,
            $cookieManager,
            $this->createMock(ResourceConnection::class),
            $this->createMock(OrderPlacer::class)
        );

        self::assertSame($expected, $manager->readCookies()['visitor_token']);
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function visitorCookies(): array
    {
        return [
            'engine vt_ token' => ['vt_8f3k2a', 'vt_8f3k2a'],
            'store-created vs_ token' => ['vs_4fK9a2LmQ7xZ0bR3tY8wP1', 'vs_4fK9a2LmQ7xZ0bR3tY8wP1'],
            'vs_ token of the wrong length' => ['vs_4fK9a2LmQ7', null],
            'neither form' => ['abc123', null],
        ];
    }

    public function testAnOrderPlacedWithLoginAsCustomerIsStampedWithNothing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('insertOnDuplicate');

        $this->manager($connection, true, 7)->saveForOrder(501);
    }

    public function testAShoppersOwnOrderIsStampedAsBefore(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insertOnDuplicate')->with(
            'smaily_order_attribution',
            [
                'order_id' => 501,
                'rec_id' => null,
                'visitor_token' => 'vt_8f3k2a',
                'rec_ctx' => null,
                'anon_session_id' => null,
            ]
        );

        $this->manager($connection, true, 0)->saveForOrder(501);
    }

    public function testWithoutLoginAsCustomerTheOrderIsStampedAndItsApiNeverResolved(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insertOnDuplicate');

        $this->manager($connection, false, null)->saveForOrder(501);
    }

    public function testAnOrderCreatedInTheAdminsOrderScreenIsStampedWithNothing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('insertOnDuplicate');

        $this->manager($connection, false, null, Area::AREA_ADMINHTML)->saveForOrder(501);
    }

    public function testAnOrderSavedWithNoAreaSetIsStamped(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insertOnDuplicate');

        $this->manager($connection, false, null, null)->saveForOrder(501);
    }

    /**
     * @param AdapterInterface $connection
     * @param bool $moduleEnabled Magento_LoginAsCustomer
     * @param int|null $adminId what its API answers; null: never resolved
     * @param string|null $area the request's area code; null: none set
     * @return AttributionManager
     */
    private function manager(
        AdapterInterface $connection,
        bool $moduleEnabled,
        ?int $adminId,
        ?string $area = Area::AREA_FRONTEND
    ): AttributionManager {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);
        $settings->method('getEngineConfig')->willReturn([]);
        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturnCallback(
            static fn (string $name) => $name === AttributionManager::DEFAULT_COOKIE_VISITOR ? 'vt_8f3k2a' : null
        );
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('isEnabled')->with('Magento_LoginAsCustomer')->willReturn($moduleEnabled);
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        if ($adminId === null) {
            $objectManager->expects(self::never())->method('get');
        } else {
            $getAdminId = new class ($adminId) {
                public function __construct(private readonly int $adminId)
                {
                }

                public function execute(): int
                {
                    return $this->adminId;
                }
            };
            $objectManager->method('get')
                ->with('Magento\\LoginAsCustomerApi\\Api\\GetLoggedAsCustomerAdminIdInterface')
                ->willReturn($getAdminId);
        }

        $appState = $this->createMock(State::class);
        if ($area === null) {
            $appState->method('getAreaCode')->willThrowException(new LocalizedException(__('Area code is not set')));
        } else {
            $appState->method('getAreaCode')->willReturn($area);
        }

        return new AttributionManager(
            $settings,
            $cookieManager,
            $resource,
            new OrderPlacer($moduleManager, $objectManager, $appState)
        );
    }
}
