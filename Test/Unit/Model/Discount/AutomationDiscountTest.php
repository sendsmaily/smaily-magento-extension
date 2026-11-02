<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Discount;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Discount\AutomationDiscount;
use Smaily\Connect\Model\Discount\CodeIssuer;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;

/**
 * CON-256 (the WooCommerce plugin's CON-255): an automation that is switched
 * off keeps its saved discount setting and makes no code, read through the
 * switch the automation's own sending reads; switched on, it makes one.
 */
class AutomationDiscountTest extends TestCase
{
    private const WEBSITE_ID = 3;

    /**
     * @dataProvider automationSwitches
     */
    public function testNoCodeIsMadeForAnAutomationThatIsSwitchedOff(string $trigger, string $switch): void
    {
        $on = false;
        $config = $this->createMock(Config::class);
        $config->method('isCouponEnabled')->willReturn(true);
        $config->method('getCouponRuleId')->willReturn(12);
        $config->method('getCouponValidDays')->willReturn(7);
        $config->method($switch)->with(self::WEBSITE_ID)->willReturnCallback(static function () use (&$on): bool {
            return $on;
        });
        $issuer = $this->createMock(CodeIssuer::class);
        $issuer->expects(self::once())->method('issue')
            ->willReturn(['code' => 'CODE-1', 'expires_at' => '2026-11-09']);
        $discount = new AutomationDiscount(
            $config,
            $issuer,
            $this->createMock(EventQueue::class),
            $this->createMock(Logger::class)
        );
        $payload = ['address' => ['email' => 'jane@example.test']];

        self::assertFalse($discount->automationEnabled($trigger, self::WEBSITE_ID));
        self::assertSame(
            ['email' => 'jane@example.test'],
            $discount->address($this->createMock(Event::class), $payload, $trigger, 1, self::WEBSITE_ID),
            'an automation that is off gets no code: its discount setting is only kept'
        );

        $on = true;
        [$codeField] = Trigger::COUPON_FIELDS[$trigger];
        self::assertTrue($discount->automationEnabled($trigger, self::WEBSITE_ID));
        self::assertSame(
            'CODE-1',
            $discount->address($this->createMock(Event::class), $payload, $trigger, 1, self::WEBSITE_ID)[$codeField],
            'switched on, the code is made'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function automationSwitches(): array
    {
        return [
            'welcome' => [Trigger::WELCOME, 'isWelcomeEnabled'],
            'first order' => [Trigger::FIRST_ORDER, 'isFirstOrderEnabled'],
            'abandoned cart' => [Trigger::ABANDONED_CART, 'isAbandonedCartEnabled'],
        ];
    }
}
