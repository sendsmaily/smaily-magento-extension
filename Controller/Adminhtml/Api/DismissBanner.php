<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Api;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\ViewModel\Adminhtml\IntelligenceBannerData;

/**
 * POST (form key) -> {dismissed: true}
 *
 * "Dismiss" on the Campaign Intelligence banner on top of the Settings tabs
 * and the Dashboard (CON-363): hides it for 30 days for every admin. The
 * time is kept at the default scope (IntelligenceBannerData).
 */
class DismissBanner extends AbstractOnboardingAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly DateTime $dateTime
    ) {
        parent::__construct($context, $jsonFactory, $serializer);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $refusal = $this->formKeyRefusal();
        if ($refusal !== null) {
            return $refusal;
        }

        $this->configWriter->save(
            IntelligenceBannerData::XML_PATH_DISMISSED_AT,
            (string)(int)$this->dateTime->gmtTimestamp()
        );
        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);

        return $this->answer(['dismissed' => true]);
    }
}
