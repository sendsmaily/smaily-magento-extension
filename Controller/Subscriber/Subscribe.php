<?php

namespace Smaily\SmailyForMagento\Controller\Subscriber;

use Magento\Customer\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\SmailyForMagento\Helper\Data as Helper;

/**
 * Sends new subscribers from newsletter sign-up form directly to Smaily.
 */
class Subscribe
{
    /**
     * Smaily helper class contains all methods.
     *
     * @var Smaily\SmailyForMagento\Helper\Data
     */
    protected $helper;

    /**
     * Gathers extra data from store.
     *
     * @var Magento\Store\Model\StoreManagerInterface
     */
    private $_storeManager;

    /**
     * Gathers extra data if customer is logged in when subscribing.
     *
     * @var Magento\Customer\Model\Session
     */
    private $_customerSession;

    /**
     * Gather post parameters (Name field from form).
     *
     * @var Magento\Framework\App\RequestInterface
     */
    private $_request;

    public function __construct(
        Helper $helper,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        Session $customerSession
    ) {
        $this->helper = $helper;
        $this->_request = $request;
        $this->_storeManager = $storeManager;
        $this->_customerSession = $customerSession;
    }

    /**
     * Send subscriber data to Smaily.
     *
     * @param \Magento\Newsletter\Model\Subscriber $subject
     * @param string $email
     * @return null
     */
    public function beforeSubscribe(\Magento\Newsletter\Model\Subscriber $subject, $email)
    {
        // Check Smaily extension is enabled.
        if ($this->helper->isEnabled()) {
            $autoresponderId = $this->helper->getGeneralConfig('autoresponder_id');
            $name = '';
            if ($this->_request->getPost('name')) {
                $name = (string) $this->_request->getPost('name');
            }
            // Create addtional fields array.
            $extra = [
                'name' => $name,
                'subscription_type' => 'Subscriber',
                'customer_group' => 'Guest',
                'store' => $this->_storeManager->getStore()->getName(), // Store View Name.
            ];

            if ($this->_customerSession->isLoggedIn()) {
                $cust = $this->_customerSession->getCustomer();
                // get custmer data
                $extra['customer_id'] = $cust->getId();
                $extra['customer_group'] = $this->helper->getCustomerGroupName($cust->getGroupId());
            }

            // split name field into firstname & lastname
            if (!empty($name)) {
                $name = explode(' ', trim($name));
                $extra['firstname'] = ucfirst($name[0]);
                unset($name[0]);
                if (!empty($name)) {
                    $extra['lastname'] = ucfirst(implode(' ', $name));
                }
            }

            // Send customer data to Smaily for subscription.
            $response = $this->helper->optInSubscriber($email, $extra);

            // Don't want to change email before actual function so returning null.
            return null;
        }
    }
}