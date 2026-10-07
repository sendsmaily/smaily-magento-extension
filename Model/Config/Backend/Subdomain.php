<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Smaily\Connect\Model\Client\Exception\InvalidSubdomainException;
use Smaily\Connect\Model\SmailyUrl;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * Normalizes the configured Smaily subdomain before saving, and refuses one
 * that is not a plain Smaily subdomain.
 */
class Subdomain extends Value
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly SubdomainNormalizer $normalizer,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @inheritDoc
     */
    public function beforeSave()
    {
        $subdomain = $this->normalizer->normalize((string)$this->getValue());
        if ($subdomain !== '' && !SmailyUrl::isPlainSubdomain($subdomain)) {
            throw new ValidatorException(__('%1', (new InvalidSubdomainException())->getMessage()));
        }
        $this->setValue($subdomain);

        return parent::beforeSave();
    }
}
