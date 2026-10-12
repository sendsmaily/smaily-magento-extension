<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Plugin\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessor;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Plugin\Checkout\AddNewsletterOptinToLayout;

/**
 * PRO-4036: the checkout newsletter checkbox follows the contact-sync rule —
 * it shows only once the website's contact sync is active (its Contacts
 * settings saved), as an opt-in there would reach Smaily.
 */
class AddNewsletterOptinToLayoutTest extends TestCase
{
    /**
     * @dataProvider activeProvider
     */
    public function testTheCheckboxShowsOnlyWhileContactSyncIsActive(bool $active): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isCheckoutOptinEnabled')->willReturn(true);
        $config->method('isContactSyncActive')->with(4)->willReturn($active);
        $config->method('isConnected')->willReturn(true);

        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn(4);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(9);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturn($website);
        $storeManager->method('getStore')->willReturn($store);

        $layout = (new AddNewsletterOptinToLayout($config, $storeManager))->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $this->checkoutLayout()
        );

        $afterMethods = $layout['components']['checkout']['children']['steps']['children']
            ['billing-step']['children']['payment']['children']['afterMethods']['children'];
        self::assertSame($active, isset($afterMethods['smaily-newsletter-optin']));
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function activeProvider(): array
    {
        return [
            'contact sync active' => [true],
            'Contacts settings not saved yet' => [false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutLayout(): array
    {
        return ['components' => ['checkout' => ['children' => ['steps' => ['children' => [
            'billing-step' => ['children' => ['payment' => ['children' => ['afterMethods' => ['children' => []]]]]],
        ]]]]]];
    }
}
