<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Adminhtml;

use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Adminhtml\SmailyInvitationNotice;
use Smaily\Connect\ViewModel\Adminhtml\SmailyInvitationData;

/**
 * CON-364: the invitation to connect a Smaily account as an admin system
 * message — shown while the invitation is due and not dismissed, with the
 * WooCommerce plugin's one-line notice text and links (Connect your Smaily
 * account, Create a Smaily account, the guide's API credentials step,
 * Dismiss), counted in the message bar (major).
 */
class SmailyInvitationNoticeTest extends TestCase
{
    private bool $due = true;
    private bool $dismissed = false;

    public function testShownWhileDueAndNotDismissed(): void
    {
        self::assertTrue($this->notice()->isDisplayed());

        $this->dismissed = true;
        self::assertFalse($this->notice()->isDisplayed());

        $this->dismissed = false;
        $this->due = false;
        self::assertFalse($this->notice()->isDisplayed());
    }

    public function testTheTextCarriesTheInvitationAndItsLinks(): void
    {
        self::assertSame(
            'Connect your store to Smaily to sync contacts and set up automated emails.'
            . ' <a href="https://admin.example/smaily_connect/settings/?tab=connection">Connect your Smaily account</a>'
            . ' | <a href="https://www.sendsmaily.net/account/register/" target="_blank" rel="noopener noreferrer">Create a Smaily account</a>'
            . ' | <a href="https://smaily.com/connect-magento/#setup-connect" target="_blank" rel="noopener noreferrer">'
            . 'User guide to create API credentials</a>'
            . ' <a href="https://admin.example/smaily_connect/invitation/dismiss/?form_key=form-key">Dismiss</a>',
            $this->notice()->getText()
        );
    }

    public function testItIsAMajorMessageOfItsOwn(): void
    {
        self::assertSame(MessageInterface::SEVERITY_MAJOR, $this->notice()->getSeverity());
        self::assertSame('smaily_connect_invitation', $this->notice()->getIdentity());
    }

    private function notice(): SmailyInvitationNotice
    {
        $invitation = $this->createMock(SmailyInvitationData::class);
        $invitation->method('isDue')->willReturn($this->due);
        $invitation->method('isDismissed')->willReturn($this->dismissed);
        $invitation->method('getConnectUrl')->willReturn('https://admin.example/smaily_connect/settings/?tab=connection');
        $invitation->method('getSignupUrl')->willReturn(SmailyInvitationData::SIGNUP_URL);
        $invitation->method('getGuideUrl')->willReturn('https://smaily.com/connect-magento/#setup-connect');
        $invitation->method('getDismissUrl')
            ->willReturn('https://admin.example/smaily_connect/invitation/dismiss/?form_key=form-key');

        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeUrl')->willReturnArgument(0);
        $escaper->method('escapeHtml')->willReturnCallback(static fn ($text): string => htmlspecialchars((string)$text));

        return new SmailyInvitationNotice($invitation, $escaper);
    }
}
