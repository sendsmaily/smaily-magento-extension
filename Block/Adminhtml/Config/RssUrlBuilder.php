<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Block\Adminhtml\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Website;

/**
 * Interactive feed URL builder in the Product RSS Feed config group: the
 * merchant picks category / limit / sort options and gets the ready-to-copy
 * feed URL live, without reading the query-parameter reference. The inputs
 * mirror Controller/Rss/Feed.php exactly.
 */
class RssUrlBuilder extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Smaily_Connect::config/rss-builder.phtml';

    /**
     * Storefront feed endpoint for the configuration scope being viewed.
     */
    public function getFeedBaseUrl(): string
    {
        try {
            $storeParam = $this->getRequest()->getParam('store');
            $websiteParam = $this->getRequest()->getParam('website');
            if ($storeParam) {
                $store = $this->_storeManager->getStore($storeParam);
            } elseif ($websiteParam) {
                $website = $this->_storeManager->getWebsite($websiteParam);
                $store = $website instanceof Website ? $website->getDefaultStore() : null;
            } else {
                $store = $this->_storeManager->getDefaultStoreView();
            }
        } catch (NoSuchEntityException | LocalizedException) {
            $store = null;
        }

        // Same construction as the wizard Done step: {store base URL}smaily/rss/feed.
        $baseUrl = $store !== null ? (string)$store->getBaseUrl() : '/';

        return $baseUrl . 'smaily/rss/feed';
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }
}
