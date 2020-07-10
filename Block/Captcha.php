<?php

namespace Smaily\SmailyForMagento\Block;

use \Magento\Captcha\Helper\Data;
use \Magento\Framework\View\Element\Template\Context;
use Smaily\SmailyForMagento\Helper\Data as Helper;

class Captcha extends \Magento\Captcha\Block\Captcha
{
    private $websiteId;

    public function __construct(
        Context $context,
        Data $captchaData,
        Helper $helper
    ) {
        $this->helper = $helper;
        $this->websiteId = $this->helper->getCurrentWebsiteId();
        parent::__construct($context, $captchaData);
    }

    /**
     * Renders captcha HTML (if required)
     *
     * @return string
     */
    protected function _toHtml()
    {
        if ($this->shouldCheckCaptcha() && $this->getCaptchaType() === 'magento_captcha') {
            $blockPath = $this->_captchaData->getCaptcha($this->getFormId())->getBlockName();
            $block = $this->getLayout()->createBlock($blockPath);
            $block->setData($this->getData());
            $html = $block->toHtml();
            // Should render but admin disabled for smaily_captcha form.
            if (empty($html)) {
                return $this->getErrorHtml();
            } else {
                return $html;
            }
        }
        return '';
    }

    /**
     * Returns error message if CAPTCHA is not available due to admin settings.
     *
     * @return string
     */
    public function getErrorHtml()
    {
        return '<p id="smaily-captcha-error" style="color:red;">You have enabled Smaily Newsletter form with 
        Magento built-in CAPTCHA, but the CAPTCHA is disabled in general settings.</p>';
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

    /**
     * Get CAPTCHA type (magento_captcha or google_captcha).
     *
     * @return string CAPTCHA type.
     */
    public function getCaptchaType()
    {
        return $this->helper->getCaptchaTypeForWebsite($this->websiteId);
    }
}
