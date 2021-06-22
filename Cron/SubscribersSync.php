<?php

namespace Smaily\SmailyForMagento\Cron;

use Smaily\SmailyForMagento\Helper\Config;
use Smaily\SmailyForMagento\Model\ResourceModel\SubscribersSyncState\Collection as SubscribersSyncStateCollection;
use Smaily\SmailyForMagento\Model\API\Client as SmailyAPIClient;

class SubscribersSync
{
    const BATCH_SIZE = 2500;
    const REQUIRED_FIELDS = array(
        'email',
        'is_unsubscribed',
        'name',
        'store',
    );

    protected $customerCollection;
    protected $customerGroupCollection;
    protected $newsletterSubscribersCollection;
    protected $resourceConnection;
    protected $smailyApiClient;
    protected $storeManager;

    protected $config;
    protected $subscribersSyncStateCollection;

    /**
     * Class constructor.
     *
     * @access public
     * @return void
     */
    public function __construct(
        \Magento\Customer\Model\ResourceModel\Customer\Collection $customerCollection,
        \Magento\Customer\Model\ResourceModel\Group\Collection $customerGroupCollection,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Magento\Newsletter\Model\ResourceModel\Subscriber\Collection $newsletterSubscribersCollection,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        Config $config,
        SmailyAPIClient $smailyApiClient,
        SubscribersSyncStateCollection $subscribersSyncStateCollection
    ) {
        $this->customerCollection = $customerCollection;
        $this->customerGroupCollection = $customerGroupCollection;
        $this->newsletterSubscribersCollection = $newsletterSubscribersCollection;
        $this->resourceConnection = $resourceConnection->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
        $this->storeManager = $storeManager;

        $this->config = $config;
        $this->smailyApiClient = $smailyApiClient;
        $this->subscribersSyncStateCollection = $subscribersSyncStateCollection;
    }

    /**
     * Run Newsletter Subscribers CRON job.
     *
     * @access public
     * @return void
     */
    public function run()
    {
        $nowAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $lastSyncedAt = $this->subscribersSyncStateCollection->getLastSyncedAt();
        $websites = $this->storeManager->getWebsites();

        foreach ($websites as $website) {
            if (
                $this->config->isEnabled($website) === FALSE ||
                $this->config->isSubscribersSyncEnabled($website) === FALSE
            ) {
                continue;
            }

            // Setup Smaily API client.
            $smailyApiCredentials = $this->config->getSmailyApiCredentials($website);
            $this->smailyApiClient
                ->setBaseUrl("https://${smailyApiCredentials['subdomain']}.sendsmaily.net")
                ->setCredentials($smailyApiCredentials['username'], $smailyApiCredentials['password']);

            // Synchronize Newsletter Subscribers.
            $this->optOutNewsletterSubscribers($website);
            $this->syncNewsletterSubscribers($website, $lastSyncedAt);
        }

        // Update last synchronization date.
        $this->subscribersSyncStateCollection->updateLastSyncedAt($nowAt);
    }

    /**
     * Synchronize Newsletter Subscribers from Magento to Smaily.
     *
     * @param \Magento\Store\Api\Data\WebsiteInterface $website
     * @param \DateTimeImmutable|null $lastSyncedAt
     * @access protected
     * @return void
     */
    protected function syncNewsletterSubscribers(\Magento\Store\Api\Data\WebsiteInterface $website, $lastSyncedAt = null)
    {
        $offset = 0;
        $stores = $website->getStores();
        $storeIds = $website->getStoreIds();

        // Fetch customer groups.
        $customerGroups = array();
        foreach ($this->customerGroupCollection as $customerGroup) {
            $customerGroups[$customerGroup->getId()] = $customerGroup->getCode();
        }

        // Determine list of customer fields to synchronize.
        $fieldsToSynchronize = $this->config->getSubscribersSyncFields($website);
        $fieldsToSynchronize = array_unique(array_merge(self::REQUIRED_FIELDS, $fieldsToSynchronize));
        $fieldsToSynchronize = array_combine($fieldsToSynchronize, array_fill(0, count($fieldsToSynchronize), ''));

        // Compile newsletter subscribers base query.
        //
        // Note! Using collection querying does not work, because it resets the page number if you are trying
        // to get items from outside the range of maximum number of items.
        $select = $this->resourceConnection
            ->select()
            ->from(
                ['main_table' => $this->newsletterSubscribersCollection->getMainTable()],
                ['subscriber_email', 'subscriber_status', 'customer_id', 'store_id']
            )
            ->joinLeft(
                ['customer' => $this->customerCollection->getMainTable()],
                'main_table.customer_id = customer.entity_id',
                ['firstname', 'lastname', 'group_id', 'dob', 'prefix', 'gender']
            )
            ->where('main_table.store_id IN (?)', $storeIds)
            ->where('main_table.change_status_at >= ?', !is_null($lastSyncedAt) ? $lastSyncedAt->format('Y-m-d H:i:s') : 0)
            ->order('main_table.subscriber_id ASC');

        // Synchronize Newsletter Subscribers to Smaily.
        while (true) {
            $select->limit(self::BATCH_SIZE, $offset * self::BATCH_SIZE);

            $subscribers = $this->resourceConnection->fetchAll($select);
            if (count($subscribers) === 0) {
                break;
            }

            $payload = array();
            foreach ($subscribers as $subscriber) {
                $customerGroupId = (int) $subscriber['group_id'];
                $hasCustomer = (int) $subscriber['customer_id'] > 0;

                $customerBirthday = !empty($subscriber['dob']) ? $subscriber['dob'] . ' 00:00:00' : '';
                $customerGender = $subscriber['gender'] == 2 ? 'Female' : 'Male';
                $customerGroupName = isset($customerGroups[$customerGroupId]) ? $customerGroups[$customerGroupId] : 'Customer';

                $customerStore = isset($stores[$subscriber['store_id']]) ? $stores[$subscriber['store_id']] : null;

                $data = array(
                    'email' => $subscriber['subscriber_email'],
                    'is_unsubscribed' => (int) $subscriber['subscriber_status'] === 1 ? 0 : 1,
                    'store' => !is_null($customerStore) ? $customerStore->getName() : '',
                    'name' => $hasCustomer
                        ? ucfirst($subscriber['firstname']) . ' ' . ucfirst($subscriber['lastname'])
                        : '',
                    'subscription_type' => 'Subscriber',
                    'customer_group' => $hasCustomer ? $customerGroupName : 'Guest',
                    'customer_id' => $hasCustomer ? $subscriber['customer_id'] : '',
                    'prefix' => !is_null($subscriber['prefix']) ? $subscriber['prefix'] : '',
                    'first_name' => $hasCustomer ? ucfirst($subscriber['firstname']) : '',
                    'last_name' => $hasCustomer ? ucfirst($subscriber['lastname']) : '',
                    'gender' => $hasCustomer ? $customerGender : '',
                    'birthday' => $hasCustomer ? $customerBirthday : '',
                );

                $payload[] = array_intersect_key($data, $fieldsToSynchronize);
            }

            if (!empty($payload)) {
                $this->smailyApiClient->post('/api/contact.php', $payload);
            }

            $offset += 1;
        }
    }

    /**
     * Synchronize opted-out subscribers from Smaily to Magento.
     *
     * @param \Magento\Store\Api\Data\WebsiteInterface $website
     * @access protected
     * @return void
     */
    protected function optOutNewsletterSubscribers(\Magento\Store\Api\Data\WebsiteInterface $website)
    {
        $offset = 0;
        $storeIds = $website->getStoreIds();

        while (true) {
            $subscribers = $this->smailyApiClient->get('/api/contact.php', [
                'list' => 2,
                'offset' => $offset,
                'limit' => self::BATCH_SIZE,
            ]);

            if (empty($subscribers)) {
                break;
            }

            $emailAddresses = array_column($subscribers, 'email');

            $this->newsletterSubscribersCollection
                ->getConnection()
                ->update(
                    $this->newsletterSubscribersCollection->getMaintable(),
                    ['subscriber_status' => 0],
                    [
                        'subscriber_email IN (?)' => $emailAddresses,
                        'store_id IN (?)' => $storeIds,
                    ]
                );

            $offset += 1;
        }
    }
}
