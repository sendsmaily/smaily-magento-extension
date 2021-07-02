<?php

namespace Smaily\SmailyForMagento\Plugin;

use Smaily\SmailyForMagento\Helper\Config;
use Smaily\SmailyForMagento\Helper\Data;

class OptIn
{
    protected $captchaHelper;
    protected $captchaResolver;
    protected $customerSession;
    protected $logger;
    protected $request;
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
        \Magento\Captcha\Helper\Data $captchaHelper,
        \Magento\Captcha\Observer\CaptchaStringResolver $captchaResolver,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Psr\Log\LoggerInterface $logger,
        Config $config,
        Data $dataHelper
    ) {
        $this->captchaHelper = $captchaHelper;
        $this->captchaResolver = $captchaResolver;
        $this->customerSession = $customerSession;
        $this->logger = $logger;
        $this->request = $request;
        $this->storeManager = $storeManager;

        $this->config = $config;
        $this->dataHelper = $dataHelper;
    }

    /**
     * Run additional logic when Newsletter Subscriber is about to be subscribed.
     *
     * @param \Magento\Newsletter\Model\Subscriber $subscriber
     * @param string $email
     * @access public
     * @return void
     */
    public function beforeSubscribe(\Magento\Newsletter\Model\Subscriber $subscriber, $email)
    {
        $website = $this->storeManager->getWebsite();

        if ($this->isOptInEnabled() === false ||
            $this->config->isSubscriberOptInCaptchaEnabled($website) === false
        ) {
            return;
        }

        $captchaType = $this->config->getSubscriberOptInCaptchaType($website);

        if ($captchaType === 'google_captcha') {
            $challenge = $this->request->getParam('g-recaptcha-response');

            if ($this->dataHelper->verifyGoogleCaptchaResponse($challenge) === false) {
                throw new \Magento\Framework\Exception\LocalizedException(__('Incorrect CAPTCHA.'));
            }
        } elseif ($captchaType === 'magento_captcha') {
            $formId = 'smaily_captcha';
            $challenge = $this->captchaResolver->resolve($this->request, $formId);

            if ($this->captchaHelper->getCaptcha($formId)->isCorrect($challenge) === false) {
                throw new \Magento\Framework\Exception\LocalizedException(__('Incorrect CAPTCHA.'));
            }
        }
    }

    /**
     * Run additional logic after Newsletter Subscriber is subscribed.
     *
     * @param \Magento\Newsletter\Model\Subscriber $subscriber
     * @access public
     * @return void
     */
    public function afterSubscribe(\Magento\Newsletter\Model\Subscriber $subscriber)
    {
        if ($this->isOptInEnabled() === false ||
            $subscriber->getStatus() !== \Magento\Newsletter\Model\Subscriber::STATUS_SUBSCRIBED
        ) {
            return;
        }

        // Fire opt-in request to Smaily.
        $this->optInSubscriber($subscriber);
    }

    /**
     * Run additional logic after Newsletter Subscriber confirms their subscription.
     *
     * @param \Magento\Newsletter\Model\Subscriber $subscriber
     * @access public
     * @return void
     */
    public function afterConfirm(\Magento\Newsletter\Model\Subscriber $subscriber)
    {
        if ($this->isOptInEnabled() === false) {
            return;
        }

        // Fire opt-in request to Smaily.
        $this->optInSubscriber($subscriber);
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

        // Ensure confirmation success email sending is disabled.
        $subscriber->setImportMode(true);
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

        $payload = [
            'email' => $subscriber->getSubscriberEmail(),
            'customer_id' => $customer !== null ? $customer->getId() : "",
            'customer_group' => $customer !== null
                ? $this->dataHelper->getCustomerGroupName((int) $customer->getGroupId())
                : 'Guest',
            'store' => $this->storeManager->getStore($subscriber->getStoreId())->getName(),
            'first_name' => $customer !== null ? $customer->getFirstname() : '',
            'last_name' => $customer !== null ? $customer->getLastname() : '',
        ];

        // Fire opt-in request to Smaily.
        $response = $this->dataHelper->getSmailyApiClient($website)
            ->post('/api/autoresponder.php', [
                'addresses' => [$payload],
            ]);

        if ((int) $response['code'] !== 101) {
            $this->logger->error('Smaily opt-in API request failed', ['payload' => $payload, 'response' => $response]);
        }
    }
}
