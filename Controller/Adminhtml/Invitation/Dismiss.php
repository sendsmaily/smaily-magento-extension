<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Invitation;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\ViewModel\Adminhtml\SmailyInvitationData;

/**
 * GET (form key) -> back to the page it came from
 *
 * "Dismiss" on the invitation to connect a Smaily account (CON-364): the
 * system message's link and the Dashboard banner's. A link, as Magento's
 * own "Mark as Read" on a notification and the WooCommerce plugin's
 * dismissal: the admin URL's secret key, when the store uses one, and the
 * form key in the query, checked here, so a page elsewhere cannot dismiss
 * it. Hides both for 90 days for every admin; the time is kept at the
 * default scope (SmailyInvitationData).
 */
class Dismiss extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = SmailyInvitationData::RESOURCE;

    public function __construct(
        Context $context,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly DateTime $dateTime
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect
    {
        if ($this->formKeyValidator->validate($this->getRequest())) {
            $this->configWriter->save(
                SmailyInvitationData::XML_PATH_DISMISSED_AT,
                (string)(int)$this->dateTime->gmtTimestamp()
            );
            $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
        }

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setRefererOrBaseUrl();
    }
}
