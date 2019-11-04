<?php

namespace Smaily\SmailyForMagento\Block\Captcha;

use \Magento\Captcha\Helper\Data;
use \Magento\Framework\View\Element\Template\Context;
use Smaily\SmailyForMagento\Helper\Data as Helper;

class Widget extends \Magento\Framework\View\Element\Template
{

    public function __construct(
        Context $context,
        Helper $helper
    ) {
        $this->helper = $helper;
        parent::__construct($context);
    }

    /**
     * Check if CAPTCHA should be rendered.
     *
     * @return boolean
     */
    public function shouldCheckCaptcha()
    {
        return $this->helper->shouldCheckCaptcha();
    }

    /**
     * Get CAPTCHA type (magento_captcha or google_captcha).
     *
     * @return string CAPTCHA type.
     */
    public function getCaptchaType()
    {
        return $this->helper->getCaptchaType();
    }
}
