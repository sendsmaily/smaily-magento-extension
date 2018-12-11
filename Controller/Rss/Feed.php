<?php

namespace Smaily\SmailyForMagento\Controller\Rss;

use Magento\Framework\App\Action\Context;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Directory\Model\Currency;
use Smaily\SmailyForMagento\Helper\Data as Helper;

class Feed extends \Magento\Framework\App\Action\Action
{
    protected $helperData;
    protected $objectManager;
    protected $collection;
    protected $storeManager;
    protected $currency;
    protected $category;
    protected $categoryCollection;

    public function __construct(
        Context $context,
        Helper $helperData,
        CollectionFactory $collection,
        CategoryCollectionFactory $categoryCollection,
        CategoryFactory $category,
        StoreManagerInterface $storeManager,
        Currency $currency
    ) {
        $this->helperData = $helperData;
        $this->collection = $collection;
        $this->categoryCollection = $categoryCollection;
        $this->storeManager = $storeManager;
        $this->currency = $currency;
        $this->category = $category;
        parent::__construct($context);
    }

    public function execute()
    {
        // Get category from user
        $categoryName = strip_tags($this->getRequest()->getparam('category'));
        // Get limit from user
        $limit = (int) $this->getRequest()->getParam('limit');
        if (!$limit > 0) {
            // If no limit provided use null
            $limit = null;
        }
        // If category exist generate gategory feed
        if (!empty($categoryName)) {
            $products = $this->getProductsByCategoryName($categoryName, $limit);
            $this->generateRssFeed($products);
        } else {
            // Generate rss feed from all items
            $products = $this->getLatestProducts($limit);
            $this->generateRssFeed($products);
        }
    }

    private function generateRssFeed($products)
    {
        // base url of store
        $baseUrl = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);

        // get default curreny symbol
        $currencysymbol = $this->currency->getCurrencySymbol();

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
            $price = $this->currency->format($price, array('precision'  => 2), false) . $currencysymbol;
            $splcPrice = $this->currency->format($splcPrice, array('precision'  => 2), false) . $currencysymbol;

            // get product detail page url from product object
            $url = $product->getProductUrl();
            // get product image url from product object
            $image = $product->getImage();
            if (!empty($image)) {
                $image = $baseUrl . 'pub/media/catalog/product/' . ltrim($image, '/');
            }

            // get created time of product
            $createTime = strtotime($product->getCreatedAt());

            $discount_fields = '';
            if ($discount > 0) {
                $discount_fields =
                    '<smly:old_price>' . $price . '</smly:old_price>' .
                    '<smly:discount>-' . $discount . '%</smly:discount>';
            }

            // Feed Item array
            $items[] =
            '<item>' .
                '<title>' . htmlentities($product->getName()) . '</title>' .
                '<link>' . $url . '</link>' .
                '<guid isPermaLink="True">' . $url . '</guid>' .
                '<pubDate>' . date('D, d M Y H:i:s', $createTime) . '</pubDate>' .
                '<description>' . htmlentities(($product->getData('description'))) . '</description>' .
                '<enclosure url="' . $image . '" />' .
                '<smly:price>' . $splcPrice . '</smly:price>' .
                $discount_fields .
            '</item>';
        }

        $rss =
        '<?xml version="1.0" encoding="utf-8"?>' .
        '<rss xmlns:smly="https://sendsmaily.net/schema/editor/rss.xsd" version="2.0">
            <channel>
            <title>' . $this->helperData->getConfigValue('general/store_information/name') . '</title>
            <link>' . $baseUrl . '</link>
            <description>Product Feed</description>
            <lastBuildDate>' . date('D, d M Y H:i:s') . '</lastBuildDate>' .
            implode('', $items) .
            '</channel>
        </rss>';

        return $this->getResponse()
            ->setHeader('Content-Type', 'text/xml')
            ->setBody($rss);
    }

    protected function getLatestProducts($limit = 50)
    {
        // load  product collection object
        $collection = $this->collection->create()
            ->addAttributeToSelect('*')                // set product fields to load
            ->addAttributeToSort('created_at', 'DESC') // set sorting
            ->setPageSize($limit)                      // set limit
            ->load();
        return $collection;
    }

    protected function getProductsByCategoryName($name, $limit = 50)
    {
        $categoryId = $this->getProductCategoryIdByCategoryName($name);
        $category = $this->category->create()->load($categoryId);
        $collection = $category->getProductCollection()
            ->addAttributeToSelect('*')
            ->addAttributeToSort('created_at', 'DESC') // set sorting
            ->setPageSize($limit);
        return $collection;
    }

    protected function getProductCategoryIdByCategoryName(string $name)
    {
        $id = [];
        $categories = $this->categoryCollection->create()
            ->addAttributeToSelect('*')
            ->addAttributeToFilter('name', ['like' => $name]);
        if (!empty($categories)) {
            foreach ($categories as $category) {
                $id[] = (int) $category->getId();
            }
        }
        return !empty($id) ? $id[0] : false;
    }
}
