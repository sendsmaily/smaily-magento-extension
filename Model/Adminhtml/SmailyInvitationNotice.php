<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use Smaily\Connect\ViewModel\Adminhtml\SmailyInvitationData;

/**
 * The invitation to connect a Smaily account as an admin system message
 * (CON-364): the WooCommerce plugin's one-line admin notice (CON-321), with
 * the same text and links — Connect your Smaily account (Settings >
 * Connection), Create a Smaily account, the guide's API-credentials step —
 * and Dismiss, which hides it and the Dashboard banner for 90 days for every
 * admin (SmailyInvitationData). Shown on every admin page while the
 * invitation is due and not dismissed; Magento evaluates it on each page, so
 * connecting an account removes it. Major, as CustomersImportNotice: Magento
 * counts only critical and major messages in the admin's message bar.
 * Registered in etc/adminhtml/di.xml.
 */
class SmailyInvitationNotice implements MessageInterface
{
    public function __construct(
        private readonly SmailyInvitationData $invitation,
        private readonly Escaper $escaper
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getIdentity()
    {
        return 'smaily_connect_invitation';
    }

    /**
     * @inheritDoc
     */
    public function isDisplayed()
    {
        return !$this->invitation->isDismissed() && $this->invitation->isDue();
    }

    /**
     * @inheritDoc
     */
    public function getText()
    {
        return $this->escaper->escapeHtml(
            __('Connect your store to Smaily to sync contacts and set up automated emails.')
        )
            . ' ' . $this->link($this->invitation->getConnectUrl(), (string)__('Connect your Smaily account'), false)
            . ' | ' . $this->link($this->invitation->getSignupUrl(), (string)__('Create a Smaily account'), true)
            . ' | ' . $this->link($this->invitation->getGuideUrl(), (string)__('User guide to create API credentials'), true)
            . ' ' . $this->link($this->invitation->getDismissUrl(), (string)__('Dismiss'), false);
    }

    /**
     * @inheritDoc
     */
    public function getSeverity()
    {
        return self::SEVERITY_MAJOR;
    }

    private function link(string $url, string $label, bool $external): string
    {
        return '<a href="' . $this->escaper->escapeUrl($url) . '"'
            . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>'
            . $this->escaper->escapeHtml($label) . '</a>';
    }
}
