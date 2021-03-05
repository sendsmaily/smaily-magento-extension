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

        // Normalize subdomain.
        // First, try to parse as full URL. If that fails, try to parse as subdomain.sendsmaily.net, and
        // if all else fails, then clean up subdomain and pass as is.
        if (filter_var($subdomain, FILTER_VALIDATE_URL)) {
            $url = parse_url($subdomain);
            $parts = explode('.', $url['host']);
            $subdomain = count($parts) >= 3 ? $parts[0] : '';
        } elseif (preg_match('/^[^\.]+\.sendsmaily\.net$/', $subdomain)) {
            $parts = explode('.', $subdomain);
            $subdomain = $parts[0];
        }
        $subdomain = preg_replace('/[^a-zA-Z0-9]+/', '', $subdomain);
        // Change current form subdomain value to parsed subdomain for saving to db.
        $val = $this->setValue($subdomain);

        if (empty($subdomain)) {
            throw new \Magento\Framework\Exception\ValidatorException(__('Subdomain is required.'));
        } elseif (empty($username)) {
            throw new \Magento\Framework\Exception\ValidatorException(__('Username is required.'));
        } elseif (empty($password)) {
            throw new \Magento\Framework\Exception\ValidatorException(__('Password is required.'));
        }

        $validated = $this->helper->validateApiCredentrials($subdomain, $username, $password);
        if ($validated === false) {
            throw new \Magento\Framework\Exception\ValidatorException(__('Check API credentials, unauthorized.'));
        }

        parent::beforeSave();
    }
}
