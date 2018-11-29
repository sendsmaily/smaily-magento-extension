<?php

namespace Magento\Smaily\Controller\Subscriber;

use Magento\Customer\Api\AccountManagementInterface as CustomerAccountManagement;
use Magento\Customer\Model\Session;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\App\Action\Context;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Newsletter\Model\SubscriberFactory;

class NewAction extends \Magento\Newsletter\Controller\Subscriber
{
    /**
     * @var CustomerAccountManagement
     */
    protected $customerAccountManagement;

    /**
     * Initialize dependencies.
     *
     * @param Context $context
     * @param SubscriberFactory $subscriberFactory
     * @param Session $customerSession
     * @param StoreManagerInterface $storeManager
     * @param CustomerUrl $customerUrl
     * @param CustomerAccountManagement $customerAccountManagement
     */
    public function __construct(
        Context $context,
        SubscriberFactory $subscriberFactory,
        Session $customerSession,
        StoreManagerInterface $storeManager,
        CustomerUrl $customerUrl,
        CustomerAccountManagement $customerAccountManagement
    ) {
        $this->customerAccountManagement = $customerAccountManagement;
        parent::__construct(
            $context,
            $subscriberFactory,
            $customerSession,
            $storeManager,
            $customerUrl
        );
    }

    /**
     * Validates that the email address isn't being used by a different account.
     *
     * @param string $email
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return void
     */
    protected function validateEmailAvailable($email)
    {
        $websiteId = $this->_storeManager->getStore()->getWebsiteId();
        if ($this->_customerSession->getCustomerDataObject()->getEmail() !== $email
            && !$this->customerAccountManagement->isEmailAvailable($email, $websiteId)
        ) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('This email address is already assigned to another user.')
            );
        }
    }

    /**
     * Validates that if the current user is a guest, that they can subscribe to a newsletter.
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return void
     */
    protected function validateGuestSubscription()
    {
        if ($this->_objectManager->get('Magento\Framework\App\Config\ScopeConfigInterface')
                ->getValue(
                    \Magento\Newsletter\Model\Subscriber::XML_PATH_ALLOW_GUEST_SUBSCRIBE_FLAG,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                ) != 1
            && !$this->_customerSession->isLoggedIn()
        ) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __(
                    'Sorry, but the administrator denied subscription for guests. Please <a href="%1">register</a>.',
                    $this->_customerUrl->getRegisterUrl()
                )
            );
        }
    }

    /**
     * Validates the format of the email address.
     *
     * @param string $email
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return void
     */
    protected function validateEmailFormat($email)
    {
        if (!\Zend_Validate::is($email, 'EmailAddress')) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Please enter a valid email address.'));
        }
    }

    /**
     * New subscription action
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return void
     */
    public function execute()
    {
        // check POST request
        if ($this->getRequest()->isPost() && $this->getRequest()->getPost('email')) {
            $email = (string)$this->getRequest()->getPost('email');

            try {
                // Validate EMail
                $this->validateEmailFormat($email);
                $this->validateGuestSubscription();
                $this->validateEmailAvailable($email);

                // Load Smaily Helper class
                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                $helperData = $objectManager->create('Magento\Smaily\Helper\Data');

                // check Smaily extension is enable
                if ($helperData->isEnabled()) {
                    // get Autoreponder ID
                    $autoresponder_id = $helperData->getGeneralConfig('autoresponder_id');

                    // get current customer session
                    $customerSession = $objectManager->create('Magento\Customer\Model\Session');

                    // get store manager object
                    $sm = $objectManager->create('Magento\Store\Model\StoreManagerInterface');

                    // get name of customer from Request
                    $name = (string) @$this->getRequest()->getPost('name');

                    // create addtional fields array
                    $extra = [
                        'name' => $name,
                        'subscription_type' => 'Subscriber',
                        'customer_group' => 'Guest',
                        'store' => $sm->getStore()->getStoreId(),
                    ];

                    // check customer is logged in or not
                    if ($customerSession->isLoggedIn()) {
                        // load current customer oject
                        $cust = $customerSession->getCustomer();

                        // get customer DOB
                        $dob = $cust->getDob();
                        if (!empty($dob)) {
                            $dob .= ' 00:00';
                        }

                        // get custmer data
                        $extra['customer_id'] = $cust->getId();
                        $extra['customer_group'] = $helperData->getCustomerGroupName($cust->getGroupId());
                        $extra['prefix'] = $cust->getPrefix();
                        $extra['gender'] = $cust->getGender() == 2 ? 'Female' : 'Male';
                        $extra['birthday'] = $dob;
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

                    // Send customer data to Smaily for subscription
                    $response = $helperData->subscribeAutoresponder($autoresponder_id, $email, $extra);
                    if (@$response['message'] == 'OK') {
                        $this->messageManager->addSuccess(__('Thank you for your subscription !'));
                    } else {
                        // Throw exception if an error
                        throw new \Exception(@$response['message']);
                    }
                } else {
                    // get status of subscribed customer
                    $status = $this->_subscriberFactory->create()->subscribe($email);
                    if ($status == \Magento\Newsletter\Model\Subscriber::STATUS_NOT_ACTIVE) {
                        $this->messageManager->addSuccess(__('The confirmation request has been sent.'));
                    } else {
                        $this->messageManager->addSuccess(__('Thank you for your subscription.'));
                    }
                }

            } catch (\Magento\Framework\Exception\LocalizedException $e) {
                // Throw exception if an error
                $this->messageManager->addException(
                    $e,
                    __('There was a problem with the subscription: %1', $e->getMessage())
                );

            } catch (\Exception $e) {
                // Throw exception if an error
                $this->messageManager->addException($e, __('Something went wrong with the subscription.'));
            }
        }
        // redirect to main page
        $this->getResponse()->setRedirect($this->_redirect->getRedirectUrl());
    }
}
