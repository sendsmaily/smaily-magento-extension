<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Controller\Cart;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Cart\Restore;
use Smaily\Connect\Model\AbandonedCart\RestoreTokenManager;

/**
 * The abandoned-cart restore link: a link past its lifetime lands on the
 * cart page with a notice and restores nothing.
 */
class RestoreTest extends TestCase
{
    private ?string $redirectPath = null;

    /** @var string[] */
    private array $notices = [];

    private CartRepositoryInterface&MockObject $cartRepository;

    private CheckoutSession&MockObject $checkoutSession;

    protected function setUp(): void
    {
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
    }

    public function testAnExpiredLinkLandsOnTheCartWithANoticeAndRestoresNothing(): void
    {
        $this->cartRepository->expects(self::never())->method('get');
        $this->checkoutSession->expects(self::never())->method('replaceQuote');

        $this->controller(RestoreTokenManager::EXPIRED)->execute();

        self::assertSame('checkout/cart', $this->redirectPath);
        self::assertSame(['This cart link has expired.'], $this->notices);
    }

    public function testAValidLinkRestoresTheGuestCart(): void
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIsActive'])
            ->addMethods(['getCustomerId'])
            ->getMock();
        $quote->method('getIsActive')->willReturn(true);
        $quote->method('getCustomerId')->willReturn(null);
        $this->cartRepository->method('get')->with(42)->willReturn($quote);
        $this->checkoutSession->expects(self::once())->method('replaceQuote')->with($quote);

        $this->controller(RestoreTokenManager::VALID)->execute();

        self::assertSame('checkout/cart', $this->redirectPath);
        self::assertSame([], $this->notices);
    }

    public function testAnInvalidLinkLandsOnTheCartSilently(): void
    {
        $this->cartRepository->expects(self::never())->method('get');
        $this->checkoutSession->expects(self::never())->method('replaceQuote');

        $this->controller(RestoreTokenManager::INVALID)->execute();

        self::assertSame('checkout/cart', $this->redirectPath);
        self::assertSame([], $this->notices);
    }

    private function controller(string $verdict): Restore
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnMap([
            ['id', null, '42'],
            ['ts', null, '1790000000'],
            ['token', null, 'signed'],
        ]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function (string $path) use ($redirect): Redirect {
            $this->redirectPath = $path;

            return $redirect;
        });
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->willReturn($redirect);

        $tokens = $this->createMock(RestoreTokenManager::class);
        $tokens->method('check')->with(42, '1790000000', 'signed')->willReturn($verdict);

        $messages = $this->createMock(ManagerInterface::class);
        $messages->method('addNoticeMessage')->willReturnCallback(function (string $text) use ($messages) {
            $this->notices[] = $text;

            return $messages;
        });

        return new Restore(
            $request,
            $resultFactory,
            $this->cartRepository,
            $this->checkoutSession,
            $this->createMock(CustomerSession::class),
            $tokens,
            $messages
        );
    }
}
