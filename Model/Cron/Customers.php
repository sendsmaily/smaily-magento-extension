<?php

namespace Smaily\SmailyForMagento\Model\Cron;

use \Magento\Customer\Api\CustomerRepositoryInterfaceFactory;
use \Magento\Framework\App\ResourceConnection;
use \Magento\Store\Model\StoreManagerInterface;
use \Magento\Store\Api\StoreWebsiteRelationInterface;
use Smaily\SmailyForMagento\Helper\Data as Helper;

/**
 * Rate limit for batches size.
 */
const SUBSCRIBERS_BATCH_LIMIT = 1000;

/**
 * Helper class for customer sync cron. Responsible for generating subscribers list with data.
 */
class Customers
{
    protected $connection;
    protected $customerRepository;
    protected $helperData;
    protected $resourceConnection;
    protected $storeManager;
    protected $storeWebsiteRelation;

    /**
     * Load objects
     */
    public function __construct(
        CustomerRepositoryInterfaceFactory $customerRepositoryFactory,
        Helper $helperData,
        ResourceConnection $resourceConnection,
        StoreManagerInterface $storeManager,
        StoreWebsiteRelationInterface $storeWebsiteRelation
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->connection = $this->resourceConnection->getConnection(ResourceConnection::DEFAULT_CONNECTION);
        $this->customerRepository = $customerRepositoryFactory->create();
        $this->helperData = $helperData;
        $this->storeManager = $storeManager;
        $this->storeWebsiteRelation = $storeWebsiteRelation;
    }

    /**
     * Generate subscribers list in batches.
     *
     * @param stirng $last_update Last update time
     * @return array Subscribers batches.
     */
    public function getList($last_update)
    {
        $subscribersList = [];
        $limit = SUBSCRIBERS_BATCH_LIMIT;
        $offset = 0;

        while (true) {
            $subscribers = $this->getSubscribersBatch($limit, $offset, $last_update);
            $batch = [];

            if (!$subscribers) {
                break;
            }

            foreach ($subscribers as $s) {
                $customer_id = (int) $s['customer_id'];
                $customer = $customer_id ? $this->customerRepository->getById($customer_id) : false;

                // Get DOB.
                $DOB = '';
                if ($customer) {
                    $DOB = $customer->getDob();
                    if (!empty($DOB)) {
                        $DOB .= ' 00:00';
                    }
                }

                $websiteId = (int) $this->storeManager->getStore($s['store_id'])->getWebsiteId();
                // Get fields to sync from configuration page.
                $sync_fields = $this->helperData->getGeneralConfig('fields');
                if($this->helperData->isClashingWithDefaultSettingAndOverwritten('fields', $websiteId)) {
                    $sync_fields = $this->helperData->getGeneralConfig('fields', $websiteId);
                }
                $sync_fields = explode(',', $sync_fields);

                // Create list with subscriber data.
                $subscriberData = [
                    'email' => $s['subscriber_email'],
                    'name' => $customer ? ucfirst($customer->getFirstname()).' '.ucfirst($customer->getLastname()) : '',
                    'subscription_type' => 'Subscriber',
                    'customer_group' => $customer ? $this->helperData->getCustomerGroupName($customer->getGroupId()) : 'Guest',
                    'customer_id' => $customer_id,
                    'website_id' => $websiteId,
                    'prefix' => $customer ? $customer->getPrefix() : '',
                    'firstname' => $customer ? ucfirst($customer->getFirstname()) : '',
                    'lastname' => $customer ? ucfirst($customer->getLastname()) : '',
                    'gender' => $customer ? ($customer->getGender() == 2 ? 'Female' : 'Male') : '',
                    'birthday' => $DOB,
                ];

                // Standard values always collected for subscriber.
                $subscriber = [
                    'email' => $subscriberData['email'],
                    'name' => $subscriberData['name'],
                    'store' => $this->storeManager->getStore($s['store_id'])->getName(),
                    'website_id' => $subscriberData['website_id']
                ];
                // Add values only selected in configuration page.
                foreach ($subscriberData as $key => $value) {
                    if ($key === 'email' || $key === 'name' || in_array($key, $sync_fields)) {
                        $subscriber[$key] = $value;
                    }
                }
                $batch[] = $subscriber;
            }
            $subscribersList[] = $batch;
            $offset += $limit;
        }

        return $subscribersList;
    }

    /**
     * Get subscribers batch from DB.
     *
     * @param integer $limit Limit number of rows fetched.
     * @param integer $offset Current offset of query.
     * @param boolean/string $last_update Last update time or false if first update.
     * @return array List of subscribers fetched with query.
     */
    public function getSubscribersBatch($limit, $offset, $last_update)
    {
        $binds = [];

        $table = $this->connection->getTableName('newsletter_subscriber');
        $query = "SELECT store_id, customer_id, subscriber_email FROM $table WHERE subscriber_status = '1'";

        if ($last_update) {
            $query .= " AND change_status_at > :LAST_UPDATE_TIME";
            $binds['LAST_UPDATE_TIME'] = $last_update;
        }
        // Batching.
        $query .= ' ORDER BY subscriber_id ASC';
        $query .= " LIMIT $limit";
        $query .= " OFFSET $offset";

        return $this->connection->fetchAll($query, $binds);
    }

    /**
     * Changes customer subscription status to unsubscribed in Magento database.
     *
     * @param array $unsubscribers_list List of unsubscribers emails from Smaily
     * @return void
     */
    public function removeUnsubscribers($unsubscribers_list, $websiteId)
    {
        $table = $this->connection->getTableName('newsletter_subscriber');
        $storeIds = $this->getAllStoreIdsForWebsite($websiteId);

        foreach ($unsubscribers_list as $unsubscriber_email) {
            $query = "UPDATE $table SET subscriber_status = '0' WHERE subscriber_email = :UNSUBSCRIBER_EMAIL
                AND store_id IN (:STORE_IDS)";
            $binds = ['UNSUBSCRIBER_EMAIL' => $unsubscriber_email, 'STORE_IDS' => implode(',', $storeIds)];
            $this->connection->query($query, $binds);
        }
    }

    /**
     * Get all Store IDs listed under website, return them as int in an array.
     *
     * @param string Website ID
     * @return array[int] Store IDs in array
     */
    public function getAllStoreIdsForWebsite($websiteId)
    {
        $stringStoreIds = $this->storeWebsiteRelation->getStoreByWebsiteId($websiteId);

        $intStoreIds = array_map(
            function($value) { return (int)$value; },
            $stringStoreIds
        );

        return $intStoreIds;
    }
}
