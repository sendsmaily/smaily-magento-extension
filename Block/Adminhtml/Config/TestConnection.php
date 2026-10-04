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

/**
 * "Test connection" button in the API Connection group. The click handler
 * (view/adminhtml/templates/config/assist.phtml) reads the CURRENTLY typed
 * credentials, so the merchant gets instant feedback without saving.
 */
class TestConnection extends Field
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $buttonLabel = $this->_escaper->escapeHtml((string)__('Test connection'));

        return '<button type="button" id="smaily-test-connection" class="action-default scalable">'
            . '<span>' . $buttonLabel . '</span></button>'
            . '<p id="smaily-test-connection-result" class="note" style="margin-top:0.5rem;"></p>';
    }
}
