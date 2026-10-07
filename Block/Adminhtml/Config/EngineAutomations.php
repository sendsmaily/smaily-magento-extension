<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Block\Adminhtml\Config;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Smaily\Connect\ViewModel\Adminhtml\AutomationsForm;

/**
 * Campaign Intelligence automations rendered inside the regular Automations
 * config group — engine-run triggers live next to welcome/first-order/
 * abandoned-cart, not on a separate page. Saving is AJAX (the block sits
 * inside the system-config form, so it cannot be a nested form).
 */
class EngineAutomations extends Field
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly AutomationsForm $viewModel,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @inheritDoc
     */
    public function render(AbstractElement $element)
    {
        // Full-width row: drop the scope label/default checkbox chrome.
        $element->setData('scope', null);

        return '<tr><td colspan="4" style="padding: 1.5rem 0;">' . $this->_getElementHtml($element) . '</td></tr>';
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        /** @var Template $template */
        $template = $this->getLayout()->createBlock(
            Template::class,
            'smaily.config.engine.automations',
            ['data' => [
                'template' => 'Smaily_Connect::config/engine-automations.phtml',
                'view_model' => $this->viewModel,
            ]]
        );

        return $template->toHtml();
    }
}
