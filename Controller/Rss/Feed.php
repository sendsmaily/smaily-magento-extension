<?php

namespace Smaily\SmailyForMagento\Controller\Rss;

use Smaily\SmailyForMagento\Helper\Data;
use Smaily\SmailyForMagento\Model\XML;

class Feed extends \Magento\Framework\App\Action\Action
{
    const SMLY_NAMESPACE_XSD = 'https://sendsmaily.net/schema/editor/rss.xsd';

    const RSS_DATE_FORMAT = 'r';

    protected $categoryCollectionFactory;
    protected $pricingHelper;
    protected $productCollectionFactory;
    protected $storeManager;

    protected $dataHelper;

    /**
     * Class constructor.
     *
     * @access public
     * @return void
     */
    public function __construct(
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\Pricing\Helper\Data $pricingHelper,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        Data $dataHelper
    ) {
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->pricingHelper = $pricingHelper;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->storeManager = $storeManager;

        $this->dataHelper = $dataHelper;

        parent::__construct($context);
    }

    /**
     * Serve content.
     *
     * @access public
     * @return void
     */
    public function execute()
    {
        $categoryName = strip_tags($this->getRequest()->getparam('category', ''));
        $limit = (int) $this->getRequest()->getParam('limit');

        // Normalize limit. NULL value will not apply limit.
        $limit = $limit > 0 ? $limit : null;

        if (!empty($categoryName)) {
            $products = $this->getProductsByCategoryName($categoryName, $limit);
        } else {
            $products = $this->getLatestProducts($limit);
        }

        return $this->getResponse()
            ->setHeader('Content-Type', 'text/xml')
            ->setBody($this->generateRssFeed($products)->asXML());
    }

    /**
     * Compile RSS feed.
     *
     * @param \Magento\Catalog\Model\ResourceModel\Product\Collection $products
     * @access private
     * @return \Smaily\SmailyForMagento\Model\XML
     */
    private function generateRssFeed(\Magento\Catalog\Model\ResourceModel\Product\Collection $products)
    {
        $store = $this->storeManager->getStore();
        $baseUrl = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
        $mediaUrl = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);

        $feed = $this->createRssRoot();

        // Setup RSS feed.
        $channel = $feed->addChild('channel');
        $channel->addChildWithCDATA('title', $store->getName());
        $channel->addChildWithCDATA('link', $baseUrl);
        $channel->addChild('description', 'Smaily RSS compatible product feed');
        $channel->addChild('lastBuildDate', date(self::RSS_DATE_FORMAT));

        // Add products to RSS feed.
        foreach ($products as $product) {
            $price = (float) $product->getData('price');
            $specialPrice = (float) $product->getData('special_price');
            $productUrl = $product->getProductUrl();

            // Calculate discount.
            $discount = 0;
            if ($specialPrice < $price && $specialPrice > 0.0) {
                $discount = ceil(($price - $specialPrice) / $price * 100);
            }

            // Compile feed item.
            $item = $feed->addChild('item');
            $item->addChildWithCDATA('title', $product->getName());
            $item->addChildWithCDATA('link', $productUrl);
            $item->addChildWithCData('guid', $productUrl)
                ->addAttribute('isPermalink', 'True');
            $item->addChild('pubDate', date(self::RSS_DATE_FORMAT, strtotime($product->getCreatedAt())));
            $item->addChildWithCDATA('description', $product->getData('description'));
            $item->addChild('enclosure')
                ->addAttribute('url', $mediaUrl . 'catalog/product' . $product->getImage());

            // Add pricing information to feed item.
            if ($discount > 0) {
                $formattedSpecialPrice = $this->pricingHelper->currencyByStore($specialPrice, $store, true, false);
                $formattedPrice = $this->pricingHelper->currencyByStore($price, $store, true, false);

                $item->addChild('price', $formattedSpecialPrice, self::SMLY_NAMESPACE_XSD);
                $item->addChild('old_price', $formattedPrice, self::SMLY_NAMESPACE_XSD);
                $item->addChild('discount', $discount . '%', self::SMLY_NAMESPACE_XSD);
            } else {
                $formattedPrice = $this->pricingHelper->currencyByStore($price, $store, true, false);
                $item->addChild('price', $formattedPrice, self::SMLY_NAMESPACE_XSD);
            }
        }

        return $feed;
    }

    /**
     * Fetch list of latest products.
     *
     * @param int|null $limit
     * @access protected
     * @return \Magento\Catalog\Model\ResourceModel\Product\Collection
     */
    protected function getLatestProducts($limit = 50)
    {
        return $this->productCollectionFactory->create()
            ->addAttributeToSelect('*')
            ->addAttributeToSort('created_at', 'DESC')
            ->setPageSize($limit)
            ->load();
    }

    /**
     * Fetch list of products by category name.
     *
     * @param string $name
     * @param int|null $limit
     * @access protected
     * @return \Magento\Catalog\Model\ResourceModel\Product\Collection
     */
    protected function getProductsByCategoryName($name, $limit = 50)
    {
        $collection = $this->categoryCollectionFactory->create()
            ->addAttributeToFilter('name', ['like' => $name])
            ->addAttributeToSort('name', 'ASC')
            ->setPageSize(1)
            ->load();

        if ($collection->count() === 0) {
            return $this->productCollectionFactory->create()
                ->addFieldToFilter('entity_id', 0);
        }

        return $collection->getFirstItem()
            ->getProductCollection()
            ->addAttributeToSelect('*')
            ->addAttributeToSort('created_at', 'DESC')
            ->setPageSize($limit)
            ->load();
    }

    /**
     * Create RSS feed root element.
     *
     * @access protected
     * @return \Smaily\SmailyForMagento\Model\XML
     */
    protected function createRssRoot()
    {
        $namespace = self::SMLY_NAMESPACE_XSD;
        $rss = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<rss xmlns:smly="{$namespace}" version="2.0">
</rss>
XML;
        return new XML($rss);
    }
}
