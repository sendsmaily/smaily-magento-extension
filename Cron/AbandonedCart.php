<?php

namespace Smaily\SmailyForMagento\Cron;

use Smaily\SmailyForMagento\Helper\Config;
use Smaily\SmailyForMagento\Helper\Data;
use Smaily\SmailyForMagento\Model\HTTP\ClientException;

class AbandonedCart
{
    const BATCH_SIZE = 100;
    const FIELDS_PREFIX_MAPPING = [
        'base_price' => 'product_base_price',
        'description' => 'product_description',
        'image_url' => 'product_image_url',
        'name' => 'product_name',
        'price' => 'product_price',
        'qty' => 'product_quantity',
        'sku' => 'product_sku',
    ];

    protected $dateTime;
    protected $escaper;
    protected $imageHelperFactory;
    protected $logger;
    protected $pricingHelper;
    protected $productFactory;
    protected $quoteCollection;
    protected $quoteRepository;
    protected $resourceConnection;
    protected $searchCriteriaBuilder;
    protected $sortOrderBuilder;
    protected $storeManager;

    protected $config;
    protected $dataHelper;

    /**
     * Class constructor.
     *
     * @access public
     * @return void
     */
    public function __construct(
        \Magento\Catalog\Helper\ImageFactory $imageHelperFactory,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Framework\Api\SortOrderBuilder $sortOrderBuilder,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Magento\Framework\Escaper $escaper,
        \Magento\Framework\Pricing\Helper\Data $pricingHelper,
        \Magento\Framework\Stdlib\DateTime\DateTime $dateTime,
        \Magento\Quote\Model\QuoteRepository $quoteRepository,
        \Magento\Quote\Model\ResourceModel\Quote\Collection $quoteCollection,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Psr\Log\LoggerInterface $logger,
        Config $config,
        Data $dataHelper
    ) {
        $this->dateTime = $dateTime;
        $this->escaper = $escaper;
        $this->imageHelperFactory = $imageHelperFactory;
        $this->logger = $logger;
        $this->pricingHelper = $pricingHelper;
        $this->productFactory = $productFactory;
        $this->quoteCollection = $quoteCollection;
        $this->quoteRepository = $quoteRepository;
        $this->resourceConnection = $resourceConnection
            ->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->sortOrderBuilder = $sortOrderBuilder;
        $this->storeManager = $storeManager;

        $this->config = $config;
        $this->dataHelper = $dataHelper;
    }

    /**
     * Run Abandoned Cart CRON job.
     *
     * @access public
     * @return void
     */
    public function execute()
    {
        $websites = $this->storeManager->getWebsites();

        $this->logger->info('Starting Abandoned Cart CRON job...');

        foreach ($websites as $website) {
            if ($this->config->isEnabled($website) === false ||
                $this->config->isAbandonedCartCronEnabled($website) === false
            ) {
                $this->logger->debug('CRON is disabled for website:', [
                    'id' => $website->getId(),
                    'name' => $website->getName(),
                    'code' => $website->getCode(),
                ]);
                continue;
            }

            // Trigger Abandoned Cart automation workflows.
            $this->triggerAbandonedCarts($website);
        }

        $this->logger->info('Finished Abandoned Cart CRON job');
    }

    /**
     * Trigger Abandoned Cart automation workflows in Smaily.
     *
     * @param \Magento\Store\Api\Data\WebsiteInterface $website
     * @access protected
     * @return void
     */
    protected function triggerAbandonedCarts(\Magento\Store\Api\Data\WebsiteInterface $website)
    {
        $storeIds = $website->getStoreIds();
        $tz = new \DateTimeZone('UTC');

        $this->logger->info('Triggering Smaily Abandoned Cart automation workflows...', [
            'batch_size' => self::BATCH_SIZE,
            'website' => [
                'code' => $website->getCode(),
                'id' => $website->getId(),
                'name' => $website->getName(),
            ],
        ]);

        // Determine cart abandon time.
        $nowAt = new \DateTimeImmutable('now', $tz);
        $nowAt = $nowAt->setTime((int) $nowAt->format('H'), (int) $nowAt->format('i'));

        $abandonInterval = $this->config->getAbadonedCartAbandonInterval($website);
        $nextAbandonAt = $nowAt->add($abandonInterval);

        // Compile abandoned carts base query.
        //
        // Note! Using collection querying does not work, because it resets the page number if you are trying
        // to get items from outside the range of maximum number of items.
        $select = $this->resourceConnection
            ->select()
            ->from(
                ['main_table' => $this->quoteCollection->getMainTable()],
                ['entity_id', 'reminder_date']
            )
            ->where('main_table.store_id IN (?)', $storeIds)
            ->where('main_table.is_active = ?', 1)
            ->where('main_table.items_count > ?', 0)
            ->where('main_table.customer_email IS NOT NULL')
            ->where('main_table.is_sent IS NULL')
            ->order('main_table.entity_id ASC');

        $offset = 0;
        while (true) {
            $select->limit(self::BATCH_SIZE, $offset * self::BATCH_SIZE);

            $this->logger->debug('Fetching quotes at offset: ' . $offset);

            $quotes = $this->resourceConnection->fetchAll($select);
            if (empty($quotes)) {
                $this->logger->debug('No quotes found at offset, breaking loop');
                break;
            }

            // Collect quotes to postpone or trigger abandoned cart automation for.
            $quoteIdsToTrigger = [];
            $quoteIdsToPostpone = [];
            foreach ($quotes as $quote) {
                $quoteId = (int) $quote['entity_id'];
                $abandonAt = $quote['reminder_date'] !== null
                    ? new \DateTimeImmutable($quote['reminder_date'], $tz)
                    : null;

                if ($abandonAt === null) {
                    $quoteIdsToPostpone[] = $quoteId;
                } elseif ($abandonAt <= $nowAt) {
                    $quoteIdsToTrigger[] = $quoteId;
                }
            }

            // Update postponed abandoned cart(s).
            $this->postponeAbandonedCarts($quoteIdsToPostpone, $nextAbandonAt);

            // Trigger automation workflow.
            $this->triggerAutomationWorkflows($quoteIdsToTrigger, $website);

            $offset++;
        }
    }

    /**
     * Postpone abandoned carts.
     *
     * @param array $ids
     * @param \DateTimeImmutable $abandonAt
     * @access protected
     * @return void
     */
    protected function postponeAbandonedCarts(array $ids, \DateTimeImmutable $abandonAt)
    {
        if (empty($ids)) {
            return;
        }

        $this->logger->debug('Postponing Abandoned Carts until ' . $abandonAt->format(\DateTime::ATOM), $ids);

        $this->resourceConnection->update(
            $this->quoteCollection->getMainTable(),
            ['reminder_date' => $this->dateTime->gmtDate(null, $abandonAt)],
            ['entity_id IN (?)' => $ids]
        );
    }

    /**
     * Trigger abandoned cart automation workflows in Smaily.
     *
     * @param array $ids
     * @param int $workflowId
     * @param array $fields
     * @access protected
     * @return void
     */
    protected function triggerAutomationWorkflows(array $ids, \Magento\Store\Api\Data\WebsiteInterface $website)
    {
        if (empty($ids)) {
            return;
        }

        $fields = $this->config->getAbandonedCartFields($website);
        $smailyApiClient = $this->dataHelper->getSmailyApiClient($website);
        $workflowId = $this->config->getAbandonedCartAutomationId($website);

        $this->logger->debug('Triggering Abandoned Carts', [
            'fields' => $fields,
            'ids' => $ids,
            'workflow_id' => $workflowId,
        ]);

        // Fetch quotes.
        $quotes = $this->quoteCollection
            ->clear()
            ->addFieldToFilter('entity_id', ['in' => $ids])
            ->addFieldToFilter('is_active', ['eq' => 1])
            ->load();

        foreach ($quotes as $quote) {
            $cart = [
                'email' => $quote->getCustomerEmail(),
            ];

            $this->logger->debug('Triggering Abandoned Cart for quote', [
                'quote_id' => $quote->getId(),
            ]);

            // Collect quote information.
            if (in_array('first_name', $fields, true)) {
                $cart['first_name'] = (string) $quote->getCustomerFirstname();
            }
            if (in_array('last_name', $fields, true)) {
                $cart['last_name'] = (string) $quote->getCustomerLastname();
            }

            // Collect product information.
            $visibleItems = $quote->getAllVisibleItems();
            for ($i = 0; $i < 10; $i++) {
                $item = isset($visibleItems[$i]) ? $visibleItems[$i] : null;
                $productsIndex = $i + 1;

                $cart = array_merge($cart, $this->buildCartPayload($fields, $productsIndex));

                // Skip invalid items.
                if ($item === null) {
                    continue;
                }

                $product = $this->productFactory->create()->load($item->getProductId());

                if (in_array('name', $fields, true)) {
                    $cart['product_name_' . $productsIndex] = $item->getName();
                }
                if (in_array('description', $fields, true)) {
                    $cart['product_description_' . $productsIndex] = $this->escaper
                        ->escapeHtml($product->getDescription());
                }
                if (in_array('image_url', $fields, true)) {
                    $cart['product_image_url_' . $productsIndex] = $this->imageHelperFactory
                        ->create()
                        ->init($product, 'thumbnail')
                        ->setImageFile($product->getThumbnail())
                        ->resize(346)
                        ->getUrl();
                }
                if (in_array('sku', $fields, true)) {
                    $cart['product_sku_' . $productsIndex] = $item->getSku();
                }
                if (in_array('qty', $fields, true)) {
                    $cart['product_quantity_' . $productsIndex] = $this->dataHelper
                        ->stripTrailingZeroes($item->getQty());
                }
                if (in_array('price', $fields, true)) {
                    $cart['product_price_' . $productsIndex] = $this->pricingHelper
                        ->currencyByStore($item->getPrice(), $quote->getStore(), true, false);
                }
                if (in_array('base_price', $fields, true)) {
                    $cart['product_base_price_' . $productsIndex] = $this->pricingHelper
                        ->currencyByStore($item->getBasePrice(), $quote->getStore(), true, false);
                }
            }

            $cart['over_10_products'] = $quote->getItemsCount() > 10 ? 'true' : 'false';

            // Push payload to Smaily.
            // Note! This is done one-by-one to avoid potential issues with sending abandoned cart
            // messages to recipients over-and-over.
            $payload = [
                'autoresponder' => $workflowId,
                'addresses' => [$cart],
            ];

            try {
                $response = $smailyApiClient->post('/api/autoresponder.php', $payload);

                if ((int) $response['code'] !== 101) {
                    throw new ClientException('Smaily API responded with: ' . json_encode($response));
                }
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage(), ['payload' => $payload]);

                // Re-throw exception.
                throw $e;
            }

            // Mark quote as sent.
            $this->resourceConnection->update(
                $this->quoteCollection->getMainTable(),
                ['is_sent' => 1],
                ['entity_id = ?' => $quote->getId()]
            );
        }
    }

    /**
     * Helper method to compile cart payload of selected fields.
     *
     * @param array $fields
     * @param int $index
     * @return array
     */
    protected function buildCartPayload(array $fields, $index)
    {
        $payload = [];

        if (empty($fields)) {
            return $payload;
        }

        foreach ($fields as $source) {
            $target = self::FIELDS_PREFIX_MAPPING[$source] . '_' . $index;
            $payload[$target] = '';
        }

        return $payload;
    }
}
