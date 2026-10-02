<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Block\Account;

use Magento\Customer\Block\Account\SortLink;
use Magento\Framework\App\DefaultPathInterface;
use Magento\Framework\View\Element\Template\Context;
use Smaily\Connect\Model\Engine\Settings;

/**
 * The My Account "Personalization" link, shown only where Campaign
 * Intelligence is live — the same gate as the page itself (PRO-3579).
 */
class PersonalizationLink extends SortLink
{
    /**
     * @param Context $context
     * @param DefaultPathInterface $defaultPath
     * @param Settings $engineSettings
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        DefaultPathInterface $defaultPath,
        private readonly Settings $engineSettings,
        array $data = []
    ) {
        parent::__construct($context, $defaultPath, $data);
    }

    /**
     * @inheritDoc
     */
    protected function _toHtml()
    {
        return $this->engineSettings->isSendingAllowed() ? parent::_toHtml() : '';
    }
}
