<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\ConsentingShopper;
use Smaily\Connect\Model\Engine\MarketingConsent;

/**
 * CON-266 (contract v1.23.0 §4 group c): a save is the consenting shopper's
 * own only on Magento's storefront pages, with the saved customer logged in
 * and marketing consent given there, at a store view without a Storefront
 * URL — the login's consent rule. Anywhere else the shopper's consent is
 * not visible, and the session is never started.
 */
class ConsentingShopperTest extends TestCase
{
    private string $area = Area::AREA_FRONTEND;
    private bool $loggedIn = true;
    private int $sessionCustomerId = 7;
    private bool $consent = true;
    private bool $storefrontUrl = false;

    private CustomerSession&MockObject $session;

    public function testTheLoggedInCustomersOwnSaveWithConsentIsGroupC(): void
    {
        self::assertTrue($this->shopper()->isLoggedInWithConsent(7));
    }

    public function testAnotherCustomersSaveIsNot(): void
    {
        self::assertFalse($this->shopper()->isLoggedInWithConsent(8));
        self::assertFalse($this->shopper()->isLoggedInWithConsent(0));
    }

    public function testWithoutConsentItIsNot(): void
    {
        $this->consent = false;

        self::assertFalse($this->shopper()->isLoggedInWithConsent(7));
    }

    public function testALoggedOutRequestIsNot(): void
    {
        $this->loggedIn = false;

        self::assertFalse($this->shopper()->isLoggedInWithConsent(7));
    }

    public function testAStoreViewWithAStorefrontUrlIsNot(): void
    {
        $this->storefrontUrl = true;

        self::assertFalse($this->shopper()->isLoggedInWithConsent(7));
    }

    /**
     * The admin, the REST and GraphQL APIs, cron and the command line see
     * no shopper's consent; the session is not started there.
     */
    public function testOutsideTheStorefrontTheSessionIsNotRead(): void
    {
        foreach ([Area::AREA_ADMINHTML, Area::AREA_WEBAPI_REST, Area::AREA_GRAPHQL, Area::AREA_CRONTAB, ''] as $area) {
            $this->area = $area;
            $shopper = $this->shopper();
            $this->session->expects(self::never())->method('isLoggedIn');

            self::assertFalse($shopper->isLoggedInWithConsent(7), $area);
        }
    }

    private function shopper(): ConsentingShopper
    {
        $state = $this->createMock(State::class);
        if ($this->area === '') {
            $state->method('getAreaCode')->willThrowException(new LocalizedException(__('Area code is not set')));
        } else {
            $state->method('getAreaCode')->willReturn($this->area);
        }
        $this->session = $this->createMock(CustomerSession::class);
        $this->session->method('isLoggedIn')->willReturn($this->loggedIn);
        $this->session->method('getCustomerId')->willReturn($this->sessionCustomerId);
        $consent = $this->createMock(MarketingConsent::class);
        $consent->method('isGiven')->willReturn($this->consent);
        $config = $this->createMock(Config::class);
        $config->method('hasStorefrontUrl')->willReturn($this->storefrontUrl);

        return new ConsentingShopper($state, $this->session, $consent, $config);
    }
}
