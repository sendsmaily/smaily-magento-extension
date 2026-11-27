<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Block\Widget;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Widget\Block\BlockInterface;
use Smaily\Connect\Model\Engine\Connections;

/**
 * The "Smaily recommendations" widget (etc/widget.xml) a merchant places on
 * a CMS page or block: an empty container, the same for every visitor, so
 * the page stays full-page-cacheable. After the page has loaded the
 * storefront script (engine/recommendations.phtml) asks the store for the
 * shopper's cards (Controller\Recommendations\Index) and puts them in it.
 * Nothing renders while the engine of the Campaign Intelligence connection
 * serving the store view (CON-237) may not be called, or none serves it.
 */
class Recommendations extends Template implements BlockInterface
{
    /**
     * @var string
     */
    protected $_template = 'Smaily_Connect::widget/recommendations.phtml';

    /**
     * @param Context $context
     * @param Connections $connections
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Connections $connections,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @inheritDoc
     */
    protected function _toHtml()
    {
        return $this->connections->current()?->settings()->isSendingAllowed() ? parent::_toHtml() : '';
    }
}
