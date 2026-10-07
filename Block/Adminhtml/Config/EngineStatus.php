<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Block\Adminhtml\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Smaily\Connect\Model\Engine\Settings;

/**
 * Read-only Campaign Intelligence connection status in system config.
 */
class EngineStatus extends Field
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Settings $settings,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        if (!$this->settings->isConnected()) {
            return '<span class="grid-severity-minor"><span>'
                . $this->_escaper->escapeHtml((string)__('Not connected'))
                . '</span></span>';
        }

        return '<span class="grid-severity-notice"><span>'
            . $this->_escaper->escapeHtml((string)__(
                'Connected: %1 (engine %2)',
                $this->settings->getTenantName() ?: $this->settings->getTenantId(),
                $this->settings->getEngineVersion() ?: '?'
            ))
            . '</span></span> '
            . $this->_escaper->escapeHtml((string)__(
                'Disconnect via CLI: bin/magento smaily:engine:disconnect'
            ));
    }
}
