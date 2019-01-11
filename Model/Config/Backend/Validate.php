<?php

namespace Smaily\SmailyForMagento\Model\Config\Backend;

use \Magento\Framework\Model\Context;
use \Magento\Framework\Registry;
use \Magento\Framework\App\Config\ScopeConfigInterface;
use \Magento\Framework\App\Cache\TypeListInterface;
use Smaily\SmailyForMagento\Helper\Data as Helper;

class Validate extends \Magento\Framework\App\Config\Value
{
    private $helper;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        Helper $helper,
        array $data = []
    ) {
        $this->helper = $helper;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave()
    {
        $subdomain = $this->getData('groups/general/fields/subdomain')['value'];
        $username = $this->getData('groups/general/fields/username')['value'];
        $password = $this->getData('groups/general/fields/password')['value'];

        if (empty($subdomain)) {
            throw new \Magento\Framework\Exception\ValidatorException(__('Subdomain is required.'));
        } elseif (empty($username)) {
            throw new \Magento\Framework\Exception\ValidatorException(__('Username is required.'));
        } elseif (empty($password)) {
            throw new \Magento\Framework\Exception\ValidatorException(__('Password is required.'));
        }

        $validated = $this->helper->validateApiCredentrials($subdomain, $username, $password);
        if (isset($validated) && $validated === false) {
            throw new \Magento\Framework\Exception\ValidatorException(__('Check API credentials, unauthorized.'));
        }

        parent::beforeSave();
    }
}
