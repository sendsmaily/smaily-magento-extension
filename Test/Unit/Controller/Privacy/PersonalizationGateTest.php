<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Controller\Privacy;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Privacy\Index;
use Smaily\Connect\Controller\Privacy\Save;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Privacy\ProfilingConsent;

/**
 * PRO-3579: My Account > Personalization exists only where Campaign
 * Intelligence is live (connected, and the account not refused — Woo
 * PRO-2513/PRO-3189). Elsewhere a direct request is a 404, like any page
 * Magento does not have, and a posted form changes nothing.
 */
class PersonalizationGateTest extends TestCase
{
    private ?string $forwardedTo = null;

    public function testThePageIsANotFoundWithoutCampaignIntelligence(): void
    {
        $result = $this->index(false)->execute();

        self::assertInstanceOf(Forward::class, $result);
        self::assertSame('noroute', $this->forwardedTo);
    }

    public function testThePageShowsWhereCampaignIntelligenceIsLive(): void
    {
        $result = $this->index(true)->execute();

        self::assertInstanceOf(Page::class, $result);
        self::assertNull($this->forwardedTo);
    }

    public function testASavedFormChangesNothingWithoutCampaignIntelligence(): void
    {
        $consent = $this->createMock(ProfilingConsent::class);
        $consent->expects(self::never())->method('setAllowed');

        $result = $this->save(false, $consent)->execute();

        self::assertInstanceOf(Forward::class, $result);
        self::assertSame('noroute', $this->forwardedTo);
    }

    /**
     * The tick left out is an opt-out — which is also all the "could not
     * load your preference" button posts (PRO-3591).
     */
    public function testAFormWithoutTheTickOptsOutWhereCampaignIntelligenceIsLive(): void
    {
        $consent = $this->createMock(ProfilingConsent::class);
        $consent->expects(self::once())->method('setAllowed')->with('person@example.com', false, 1);

        $result = $this->save(true, $consent)->execute();

        self::assertInstanceOf(Redirect::class, $result);
    }

    private function index(bool $live): Index
    {
        return new Index($this->session(), $this->resultFactory(), $this->settings($live));
    }

    private function save(bool $live, ProfilingConsent $consent): Save
    {
        $validator = $this->createMock(FormKeyValidator::class);
        $validator->method('validate')->willReturn(true);

        return new Save(
            $this->session(),
            $this->createMock(RequestInterface::class),
            $this->resultFactory(),
            $validator,
            $consent,
            $this->createMock(ManagerInterface::class),
            $this->settings($live)
        );
    }

    private function settings(bool $live): Settings
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isSendingAllowed')->willReturn($live);

        return $settings;
    }

    private function session(): CustomerSession
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('person@example.com');
        $customer->method('getStoreId')->willReturn(1);

        $session = $this->createMock(CustomerSession::class);
        $session->method('isLoggedIn')->willReturn(true);
        $session->method('getCustomerData')->willReturn($customer);

        return $session;
    }

    private function resultFactory(): ResultFactory
    {
        $forward = $this->createMock(Forward::class);
        $forward->method('forward')->willReturnCallback(function (string $action) use ($forward) {
            $this->forwardedTo = $action;

            return $forward;
        });

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();

        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($this->createMock(Title::class));
        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($pageConfig);

        $factory = $this->createMock(ResultFactory::class);
        $factory->method('create')->willReturnCallback(static fn (string $type) => match ($type) {
            ResultFactory::TYPE_FORWARD => $forward,
            ResultFactory::TYPE_REDIRECT => $redirect,
            ResultFactory::TYPE_PAGE => $page,
            default => throw new \LogicException('Unexpected result type ' . $type),
        });

        return $factory;
    }
}
