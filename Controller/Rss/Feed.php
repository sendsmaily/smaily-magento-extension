<?php

namespace Smaily\SmailyForMagento\Controller\Rss;

use Smaily\SmailyForMagento\Helper\Data;
use Smaily\SmailyForMagento\Model\XML;

class Feed extends \Magento\Framework\App\Action\Action
{
    const SMLY_NAMESPACE_XSD = 'https://sendsmaily.net/schema/editor/rss.xsd';

    const RSS_DATE_FORMAT = 'r';

    protected $categoryCollectionFactory;
    protected $configurableProductType;
    protected $dataHelper;
    protected $pricingHelper;
    protected $productCollectionFactory;
    protected $productRepository;
    protected $storeManager;
    protected $taxHelper;

    /**
     * Class constructor.
     *
     * @access public
     * @return void
     */
    public function __construct(
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        \Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable $configurableProductType,
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\Pricing\Helper\Data $pricingHelper,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Tax\Model\Calculation $taxHelper,
        Data $dataHelper
    ) {
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->configurableProductType = $configurableProductType;
        $this->pricingHelper = $pricingHelper;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->taxHelper = $taxHelper;
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
            /** @var \Magento\Catalog\Model\Product $product */
            $price = $this->getPriceIncludingTax($product);
            if ($price == 0.0) {
                // Probably a grouped product.
                continue;
            }
            $specialPrice = $this->getFinalPriceIncludingTax($product);
            $discount = $this->calculateDiscountPercentage($product);

            $productUrl = $this->getProductUrl($product);

            // Compile feed item.
            $item = $channel->addChild('item');
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

    /**
     * Get product price including tax.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return float
     */
    private function getPriceIncludingTax($product)
    {
        $priceExclTax = $product->getPrice();
        $taxClassId = $product->getTaxClassId();

        $taxRequest = $this->taxHelper->getRateRequest();
        $taxRequest->setProductClassId($taxClassId);

        $taxRate = $this->taxHelper->getRate($taxRequest);
        $taxAmount = $this->taxHelper->calcTaxAmount(
            $priceExclTax,
            $taxRate,
        );

        return $priceExclTax + $taxAmount;
    }

    /**
     * Get product final price including tax.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return float
     */
    private function getFinalPriceIncludingTax($product)
    {
        $finalPrice = $product->getFinalPrice();

        if ($finalPrice == 0.0) {
            return 0.0;
        }

        $taxClassId = $product->getTaxClassId();
        $taxRequest = $this->taxHelper->getRateRequest();
        $taxRequest->setProductClassId($taxClassId);

        $taxRate = $this->taxHelper->getRate($taxRequest);
        $taxAmount = $this->taxHelper->calcTaxAmount(
            $finalPrice,
            $taxRate,
        );

        return $finalPrice + $taxAmount;
    }

    /**
     * Calculate product discount percentage.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return int
     */
    private function calculateDiscountPercentage($product)
    {
        $price = $product->getPrice();

        if ($price == 0.0) {
            return 0;
        }

        $finalPrice = $product->getFinalPrice();

        if ($finalPrice >= $price || $finalPrice == 0.0) {
            return 0;
        }

        return ceil(($price - $finalPrice) / $price * 100);
    }

    /**
     * Get the product URL.
     *
     * @param \Magento\Catalog\Model\Product $product
     */
    private function getProductURL($product)
    {
        $url = $product->getProductUrl();

        // Magento shows URL that results in 404 for products that are not visible.
        // We want to return the parent product URL instead, so that the link is valid.
        if (!$product->isVisibleInSiteVisibility()) {
            $parentIds = $this->configurableProductType->getParentIdsByChild($product->getId());
            if (count($parentIds) > 0) {
                $parentId = $parentIds[0]; // Get the first parent ID
                $parentProduct = $this->productRepository->getById($parentId);
                return $parentProduct->getProductUrl();
            }
        }

        return $url;
    }
}
