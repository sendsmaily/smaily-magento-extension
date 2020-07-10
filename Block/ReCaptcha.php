<?php

namespace Smaily\SmailyForMagento\Block;

use \Magento\Framework\View\Element\Template;
use \Magento\Framework\View\Element\Template\Context;
use Smaily\SmailyForMagento\Helper\Data as Helper;

class ReCaptcha extends Template
{
    private $helper;
    private $websiteId;

    public function __construct(
        Context $context,
        Helper $helper
    ) {
        parent::__construct($context);
        $this->helper = $helper;
        $this->websiteId = $this->helper->getCurrentWebsiteId();
    }

    /**
     * Get CAPTCHA type (magento_captcha or google_captcha).
     *
     * @return string CAPTCHA type.
     */
    public function getCaptchaType()
    {
        return $this->helper->getCaptchaTypeForWebsite($this->websiteId);
    }

    /**
     * Get reCAPTCHA public API key.
     *
     * @return string CAPTCHA public key.
     */
    public function getCaptchaApiKey()
    {
        return $this->helper->getCaptchaApiKey();
    }

    /**
     * Check if CAPTCHA should be rendered.
     *
     * @return boolean
     */
    public function shouldCheckCaptcha()
    {
        return $this->helper->shouldCheckCaptchaForWebsite($this->websiteId);
    }
}
