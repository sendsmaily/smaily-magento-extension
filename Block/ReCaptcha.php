<?php

namespace Smaily\SmailyForMagento\Block;

use \Magento\Framework\View\Element\Template;
use \Magento\Framework\View\Element\Template\Context;
use Smaily\SmailyForMagento\Helper\Data as Helper;

class ReCaptcha extends Template
{
    private $helper;

    public function __construct(
        Context $context,
        Helper $helper
    ) {
        parent::__construct($context);
        $this->helper = $helper;
    }

    /**
     * Get CAPTCHA type (magento_captcha or google_captcha).
     *
     * @return string Captcha type.
     */
    public function getCaptchaType()
    {
        return $this->helper->getCaptchaType();
    }

    /**
     * Get reCAPTCHA public api key.
     *
     * @return void
     */
    public function getCaptchaApiKey()
    {
        return $this->helper->getCaptchaApiKey();
    }
}
