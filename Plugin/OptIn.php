<?php

namespace Smaily\SmailyForMagento\Plugin;

use Smaily\SmailyForMagento\Helper\Config;
use Smaily\SmailyForMagento\Helper\Data;

class OptIn
{
    protected $customerSession;
    protected $logger;
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
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Psr\Log\LoggerInterface $logger,
        Config $config,
        Data $dataHelper
    ) {
        $this->customerSession = $customerSession;
        $this->logger = $logger;
        $this->storeManager = $storeManager;

        $this->config = $config;
        $this->dataHelper = $dataHelper;
    }

    /**
     * Run additional logic before sending confirmation success email.
     *
     * @param \Magento\Newsletter\Model\Subscriber $subscriber
     * @access public
     * @return void
     */
    public function beforeSendConfirmationSuccessEmail(\Magento\Newsletter\Model\Subscriber $subscriber)
    {
        if ($this->isOptInEnabled() === false) {
            return;
        }

        $initialImportMode = $subscriber->getImportMode();

        // Ensure confirmation success email sending is disabled.
        $subscriber->setImportMode(true);

        // Opt-in subscriber in Smaily.
        if ($initialImportMode !== true) {
            $this->optInSubscriber($subscriber);
        }
    }

    /**
     * Run additional logic before sending unsubscribe email.
     *
     * @param \Magento\Newsletter\Model\Subscriber $subscriber
     * @access public
     * @return void
     */
    public function beforeSendUnsubscriptionEmail(\Magento\Newsletter\Model\Subscriber $subscriber)
    {
        if ($this->isOptInEnabled() === false) {
            return;
        }

        // Ensure unsubscribe email sending is disabled.
        $subscriber->setImportMode(true);
    }

    /**
     * Helper method to check if Newsletter Subscriber opt-in functionality is enabled.
     *
     * @access protected
     * @return boolean
     */
    protected function isOptInEnabled()
    {
        $website = $this->storeManager->getWebsite();
        return (
            $this->config->isEnabled($website) === true &&
            $this->config->isSubscriberOptInEnabled($website) === true
        );
    }

    /**
     * Send opt-in request to Smaily.
     *
     * @param \Magento\Newsletter\Model\Subscriber $subscriber
     * @access protected
     * @return void
     */
    protected function optInSubscriber(\Magento\Newsletter\Model\Subscriber $subscriber)
    {
        $website = $this->storeManager->getWebsite();

        // Compile subscriber payload.
        $customer = null;
        if ($this->customerSession->isLoggedIn()) {
            $customer = $this->customerSession->getCustomer();
        }

        $store = $this->storeManager->getStore($subscriber->getStoreId());
        $storeGroup = $store->getGroup();
        $storeWebsite = $store->getWebsite();

        $data = [
            'email' => $subscriber->getSubscriberEmail(),
            'customer_id' => $customer !== null ? $customer->getId() : "",
            'customer_group' => $customer !== null
                ? $this->dataHelper->getCustomerGroupName((int) $customer->getGroupId())
                : 'Guest',
            'store' => $store->getName(),
            'store_group' => $storeGroup->getName(),
            'store_website' => $storeWebsite->getName(),
            'first_name' => $customer !== null ? $customer->getFirstname() : '',
            'last_name' => $customer !== null ? $customer->getLastname() : '',
        ];

        // Fire opt-in request to Smaily.
        $workflowId = $this->config->getSubscriberOptInWorkflowId($website);

        $payload = ['addresses' => [$data]];
        if ($workflowId > 0) {
            $payload['autoresponder'] = $workflowId;
        }

        $response = $this->dataHelper->getSmailyApiClient($website)
            ->post('/api/autoresponder.php', $payload);

        if ((int) $response['code'] !== 101) {
            $this->logger->error('Smaily opt-in API request failed', ['payload' => $payload, 'response' => $response]);
        }
    }
}
