<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\Settings;

/**
 * The visitor-token cookie is read in either form the contract allows, an
 * engine `vt_` token or a store-created `vs_` token (PRO-3912); a value of
 * neither form reads as absent. Values are synthetic.
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
            $this->createMock(ResourceConnection::class)
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
}
