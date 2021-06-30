<?php

namespace Smaily\SmailyForMagento\Block;

use Smaily\SmailyForMagento\Helper\Config;

class ReCaptcha extends \Magento\Framework\View\Element\Template
{
    protected $storeManager;

    protected $config;

    /**
     * Class constructor.
     *
     * @access public
     * @return void
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        Config $config
    )
    {
        $this->storeManager = $storeManager;

        $this->config = $config;

        parent::__construct($context);
    }

    /**
     * Is Google reCAPTCHA enabled?
     *
     * @access public
     * @return boolean
     */
    public function isEnabled()
    {
        $website = $this->storeManager->getWebsite();
        return (
            $this->config->isEnabled($website) === true &&
            $this->config->isSubscriberOptInEnabled($website) === true &&
            $this->config->isSubscriberOptInCaptchaEnabled($website) === true &&
            $this->config->getSubscriberOptInCaptchaType($website) === 'google_captcha'
        );
    }

    /**
     * Get Google reCAPTCHA site key.
     *
     * @access public
     * @return string
     */
    public function getSiteKey()
    {
        $website = $this->storeManager->getWebsite();
        return $this->config->getSubscriberOptInCaptchaSiteKey($website);
    }
}
