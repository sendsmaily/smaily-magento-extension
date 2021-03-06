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
    private $storeManager;

    /**
     * Gathers extra data if customer is logged in when subscribing.
     *
     * @var Magento\Customer\Model\Session
     */
    private $customerSession;

    /**
     * Gather post parameters (Name field from form).
     *
     * @var Magento\Framework\App\RequestInterface
     */
    private $request;

    public function __construct(
        Helper $helper,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        Session $customerSession
    ) {
        $this->helper = $helper;
        $this->request = $request;
        $this->storeManager = $storeManager;
        $this->customerSession = $customerSession;
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
        // Check Smaily extension/newsletter subscribers collection are enabled.
        if ($this->helper->isEnabled() && $this->helper->isNewsletterSubscriptionEnabled()) {
            // Create addtional fields array.
            $extra = [
                'customer_id' => '',
                'customer_group' => 'Guest',
                'store' => $this->storeManager->getStore()->getName(), // Store View Name.
            ];

            if ($this->customerSession->isLoggedIn()) {
                $cust = $this->customerSession->getCustomer();
                // get custmer data
                $extra['customer_id'] = $cust->getId();
                $extra['customer_group'] = $this->helper->getCustomerGroupName($cust->getGroupId());
            }

            // Get all form params and add to request data.
            $params = $this->request->getParams();
            unset($params['form_key']);

            // Unset CAPTCHA if set.
            if (array_key_exists('captcha', $params)) {
                unset($params['captcha']);
            }

            // Unset reCAPTCHA if set.
            if (array_key_exists('g-recaptcha-response', $params)) {
                unset($params['g-recaptcha-response']);
            }

            foreach ($params as $key => $value) {
                $extra[$key] = $value;
            }

            // Send customer data to Smaily for subscription.
            $response = $this->helper->optInSubscriber($email, $extra);

            // Don't want to change email before actual function so returning null.
            return null;
        }
    }
}
