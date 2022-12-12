<?php

namespace Smaily\SmailyForMagento\Cron;

use Smaily\SmailyForMagento\Helper\Config;
use Smaily\SmailyForMagento\Helper\Data;
use Smaily\SmailyForMagento\Model\HTTP\ClientException;

class SubscribersSync
{
    const BATCH_SIZE = 2500;
    const REQUIRED_FIELDS = [
        'email',
        'is_unsubscribed',
        'name',
        'store',
        'store_group',
        'store_website',
    ];

    protected $customerCollection;
    protected $logger;
    protected $newsletterSubscribersCollection;
    protected $resourceConnection;
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
        \Magento\Customer\Model\ResourceModel\Customer\Collection $customerCollection,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Magento\Newsletter\Model\ResourceModel\Subscriber\Collection $newsletterSubscribersCollection,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Psr\Log\LoggerInterface $logger,
        Config $config,
        Data $dataHelper
    ) {
        $this->customerCollection = $customerCollection;
        $this->logger = $logger;
        $this->newsletterSubscribersCollection = $newsletterSubscribersCollection;
        $this->resourceConnection = $resourceConnection
            ->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
        $this->storeManager = $storeManager;

        $this->config = $config;
        $this->dataHelper = $dataHelper;
    }

    /**
     * Run Newsletter Subscribers CRON job.
     *
     * @access public
     * @return void
     */
    public function execute()
    {
        $websites = $this->storeManager->getWebsites();

        $this->logger->info('Starting Newsletter Subscribers synchronization CRON job...');

        foreach ($websites as $website) {
            if ($this->config->isEnabled($website) === false ||
                $this->config->isSubscribersSyncEnabled($website) === false
            ) {
                $this->logger->debug('CRON is disabled for website:', [
                    'id' => $website->getId(),
                    'name' => $website->getName(),
                    'code' => $website->getCode(),
                ]);
                continue;
            }

            $nowAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $lastSyncedAt = $this->config->getSubscribersSyncLastSyncedAt($website->getId());

            // Synchronize Newsletter Subscribers.
            $this->optOutNewsletterSubscribers($website);
            $this->syncNewsletterSubscribers($website, $lastSyncedAt);

            // Update last synchronization date.
            $this->config->setSubscribersSyncLastSyncedAt($website->getId(), $nowAt);
        }

        $this->logger->info('Finished Newsletter Subscribers synchronization CRON job');
    }

    /**
     * Synchronize Newsletter Subscribers from Magento to Smaily.
     *
     * @param \Magento\Store\Api\Data\WebsiteInterface $website
     * @param \DateTimeImmutable|null $lastSyncedAt
     * @access protected
     * @return void
     */
    protected function syncNewsletterSubscribers(
        \Magento\Store\Api\Data\WebsiteInterface $website,
        $lastSyncedAt = null
    ) {
        $smailyApiClient = $this->dataHelper->getSmailyApiClient($website);
        $groups = $website->getGroups();
        $storeIds = $website->getStoreIds();
        $stores = $website->getStores();

        $this->logger->info('Synchronizing subscribers from Magento to Smaily...', [
            'batch_size' => self::BATCH_SIZE,
            'since_dt' => $lastSyncedAt,
            'website' => [
                'code' => $website->getCode(),
                'id' => $website->getId(),
                'name' => $website->getName(),
            ],
        ]);

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
            ->where(
                'main_table.change_status_at >= ?',
                $lastSyncedAt !== null ? $lastSyncedAt->format('Y-m-d H:i:s') : 0
            )
            ->order('main_table.subscriber_id ASC');

        // Synchronize Newsletter Subscribers to Smaily.
        $offset = 0;
        while (true) {
            $select->limit(self::BATCH_SIZE, $offset * self::BATCH_SIZE);

            $this->logger->debug('Fetching subscribers at offset: ' . $offset);

            $subscribers = $this->resourceConnection->fetchAll($select);
            if (empty($subscribers)) {
                $this->logger->debug('No subscribers found at offset, breaking loop');
                break;
            }

            $payload = [];
            foreach ($subscribers as $subscriber) {
                $customerGroupId = (int) $subscriber['group_id'];
                $hasCustomer = (int) $subscriber['customer_id'] > 0;

                $customerBirthday = !empty($subscriber['dob']) ? $subscriber['dob'] . ' 00:00:00' : '';
                $customerGender = $subscriber['gender'] == 2 ? 'Female' : 'Male';
                $customerGroupName = $this->dataHelper->getCustomerGroupName($customerGroupId);

                $customerStore = isset($stores[$subscriber['store_id']]) ? $stores[$subscriber['store_id']] : null;
                $customerStoreGroup = $customerStore !== null && isset($groups[$customerStore->getGroupId()])
                    ? $groups[$customerStore->getGroupId()]
                    : null;

                $data = [
                    'email' => $subscriber['subscriber_email'],
                    'is_unsubscribed' => (int) $subscriber['subscriber_status']
                        === \Magento\Newsletter\Model\Subscriber::STATUS_SUBSCRIBED ? 0 : 1,
                    'store' => $customerStore !== null ? $customerStore->getName() : '',
                    'store_group' => $customerStoreGroup !== null ? $customerStoreGroup->getName() : '',
                    'store_website' => $website->getName(),
                    'name' => $hasCustomer
                        ? ucfirst($subscriber['firstname']) . ' ' . ucfirst($subscriber['lastname'])
                        : '',
                    'subscription_type' => 'Subscriber',
                    'customer_group' => $hasCustomer ? $customerGroupName : 'Guest',
                    'customer_id' => $hasCustomer ? $subscriber['customer_id'] : '',
                    'prefix' => $subscriber['prefix'] !== null ? $subscriber['prefix'] : '',
                    'first_name' => $hasCustomer ? ucfirst($subscriber['firstname']) : '',
                    'last_name' => $hasCustomer ? ucfirst($subscriber['lastname']) : '',
                    'gender' => $hasCustomer ? $customerGender : '',
                    'birthday' => $hasCustomer ? $customerBirthday : '',
                ];

                $payload[] = array_intersect_key($data, $fieldsToSynchronize);
            }

            if (!empty($payload)) {
                $response = $smailyApiClient->post('/api/contact.php', $payload);

                if ((int) $response['code'] !== 101) {
                    throw new ClientException('Smaily API responded with: ' . json_encode($response));
                }
            }

            $offset++;
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
        $smailyApiClient = $this->dataHelper->getSmailyApiClient($website);
        $storeIds = $website->getStoreIds();

        $timezoneUTC = new \DateTimeZone('UTC');
        $timezoneLocal = new \DateTimeZone('Europe/Tallinn');

        $this->logger->info('Synchronizing opt-outs from Smaily to Magento...', [
            'batch_size' => self::BATCH_SIZE,
            'website' => [
                'code' => $website->getCode(),
                'id' => $website->getId(),
                'name' => $website->getName(),
            ],
        ]);

        $offset = 0;
        while (true) {
            $this->logger->debug('Fetching opt-outs at offset: ' . $offset);

            $unsubscribers = $smailyApiClient->get('/api/contact.php', [
                'list' => 2,
                'fields' => 'email,unsubscribed_at',
                'offset' => $offset,
                'limit' => self::BATCH_SIZE,
            ]);

            if (empty($unsubscribers)) {
                $this->logger->debug('No opt-outs found at offset, breaking loop');
                break;
            }

            $smailyUnsubscribers = [];
            foreach ($unsubscribers as $unsubscriber) {
                $email = $unsubscriber['email'];
                $unsubscribedAt = new \DateTimeImmutable($unsubscriber['unsubscribed_at'], $timezoneLocal);

                $smailyUnsubscribers[$email] = $unsubscribedAt->setTimezone($timezoneUTC);
            }

            $select = $this->resourceConnection
                ->select()
                ->from(
                    ['main_table' => $this->newsletterSubscribersCollection->getMainTable()],
                    ['subscriber_email', 'change_status_at']
                )
                ->where('main_table.store_id IN (?)', $storeIds)
                ->where('main_table.subscriber_email IN (?)', array_keys($smailyUnsubscribers))
                ->where('main_table.subscriber_status = ?', \Magento\Newsletter\Model\Subscriber::STATUS_SUBSCRIBED);

            $subscribers = $this->resourceConnection->fetchAll($select);

            foreach ($subscribers as $subscriber) {
                $emailAddress = $subscriber['subscriber_email'];
                $changeStatusAt = new \DateTimeImmutable($subscriber['change_status_at'], $timezoneUTC);
                $unsubscribedAt = $smailyUnsubscribers[$emailAddress];

                if ($changeStatusAt >= $unsubscribedAt) {
                    continue;
                }

                $this->newsletterSubscribersCollection
                    ->getConnection()
                    ->update(
                        $this->newsletterSubscribersCollection->getMaintable(),
                        [
                            'subscriber_status' => \Magento\Newsletter\Model\Subscriber::STATUS_UNSUBSCRIBED,
                            'change_status_at' => $unsubscribedAt->format('Y-m-d H:i:s'),
                        ],
                        [
                            'subscriber_email = ?' => $emailAddress,
                            'store_id IN (?)' => $storeIds,
                        ]
                    );
            }

            $offset++;
        }
    }
}
