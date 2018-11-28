<?php

namespace Magento\Smaily\Controller\Rss;

use Magento\Framework\App\Action\Context;

class Feed extends \Magento\Framework\App\Action\Action
{
    protected $helperData;
    protected $objectManager;

    public function __construct(Context $context)
    {
        // create object manager object
        $this->objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        // load Smaily helper class
        $helperData = $this->objectManager->create('Magento\Smaily\Helper\Data');
        $this->helperData = $helperData;

        // call parent class function
        parent::__construct($context);
    }

    public function execute()
    {
        // check Smaily exenstion and valiate Token
        if ((int) @$this->helperData->getGeneralConfig('enable') && trim($this->helperData->getGeneralConfig('feed_token')) == trim(@$this->getRequest()->getParam('token'))) {
            // call to Genenate Rss Feed function
            $this->generateRssFeed(50);
        } else {
            echo 'Access Denied !';
        }
        exit;
    }

    private function generateRssFeed($limit = 50)
    {
        // Get latest products
        $products = $this->getLatestProducts($limit);

        // load store manager object
        $storeManager = $this->objectManager->get('\Magento\Store\Model\StoreManagerInterface');

        // get magento base url
        $baseUrl = $storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);

        // get default curreny symbol
        $currencysymbol = $this->objectManager->get('Magento\Directory\Model\Currency')->getCurrencySymbol();

        $items = [];

        // create rss feed item list
        foreach ($products as $product) {
            $price = $product->getPrice() * 1;
            $splcPrice = $product->getData('special_price') * 1;
            $discount = 0;

            if ($splcPrice == 0) {
                $splcPrice = $price;
            }

            // calculate discount
            if ($splcPrice < $price && $price > 0) {
                $discount = ceil(($price - $splcPrice) / $price * 100);
            }

            // format price
            $price = $currencysymbol . number_format($price, 2, '.', ',');
            $splcPrice = $currencysymbol . number_format($splcPrice, 2, '.', ',');


            // get product detail page url from product object
            $url = $product->getProductUrl();
            // get product image url from product object
            $image = $product->getImage();
            if (!empty($image)) {
                $image = $baseUrl . 'pub/media/catalog/product/' . ltrim($image, '/');
            }

            // get created time of product
            $createTime = strtotime($product->getCreatedAt());

            $price_fields = '';
            if ($discount > 0) {
                $price_fields = '
              <smly:old_price>' . $price . '</smly:old_price>
              <smly:discount>-' . $discount . '%</smly:discount>';
            }

            // Feed Item array
            $items[] = '<item>
                <title>'.$product->getName().'</title>
                <link>'.$url.'</link>
                <guid isPermaLink="True">'.$url.'</guid>
                <pubDate>'.date('D, d M Y H:i:s', $createTime).'</pubDate>
                <description>'.htmlentities($product->getData('description')).'</description>
                <enclosure url="'.$image.'" />
                <smly:price>'.$splcPrice.'</smly:price>'.
                $price_fields.
            '</item>';
        }

        $rss = '<?xml version="1.0" encoding="utf-8"?>
            <rss xmlns:smly="https://sendsmaily.net/schema/editor/rss.xsd" version="2.0">
            <channel>
                <title>'.$this->helperData->getConfigValue('general/store_information/name').'</title>
                <link>'.$baseUrl.'</link>
                <description>Product Feed</description>
                <lastBuildDate>'.date('D, d M Y H:i:s').'</lastBuildDate>' .
                implode(' ', $items) .
            '</channel>
        </rss>';

        // render created feed.
        header('Content-Type: application/xml');
        echo $rss;
    }

    public function getLatestProducts($limit)
    {
        // load  product collection object
        $productCollection = $this->objectManager
            ->create('Magento\Catalog\Model\ResourceModel\Product\CollectionFactory');
        $collection = $productCollection->create()
            ->addAttributeToSelect('*')                // set product fields to load
            ->addAttributeToSort('created_at', 'DESC') // set sorting
            ->setPageSize($limit)                      // set limit
            ->load();

        return $collection;
    }
}
