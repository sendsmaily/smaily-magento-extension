<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\AbandonedCart;

use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\AbandonedCart\GuestCartEmailConfigProvider;
use Smaily\Connect\Model\Config;

/**
 * PRO-3693: the checkout learns whether to send a guest's email to the
 * cart from the website's abandoned-cart switch.
 */
class GuestCartEmailConfigProviderTest extends TestCase
{
    /**
     * @dataProvider switches
     */
    public function testTheCheckoutFollowsTheWebsitesAbandonedCartSwitch(bool $enabled): void
    {
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn(3);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturn($website);
        $config = $this->createMock(Config::class);
        $config->expects(self::once())->method('isAbandonedCartEnabled')->with(3)->willReturn($enabled);

        self::assertSame(
            ['smailyGuestCartEmail' => $enabled],
            (new GuestCartEmailConfigProvider($config, $storeManager))->getConfig()
        );
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function switches(): array
    {
        return ['on' => [true], 'off' => [false]];
    }
}
