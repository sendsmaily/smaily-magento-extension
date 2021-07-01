<?php

namespace Smaily\SmailyForMagento\Block;

use Smaily\SmailyForMagento\Helper\Config;

class Captcha extends \Magento\Captcha\Block\Captcha
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
        \Magento\Captcha\Helper\Data $captchaData,
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        Config $config
    ) {
        $this->storeManager = $storeManager;

        $this->config = $config;

        parent::__construct($context, $captchaData);
    }

    /**
     * Render CAPTCHA HTML, if enabled.
     *
     * @access protected
     * @return string
     */
    protected function _toHtml()
    {
        $website = $this->storeManager->getWebsite();

        if ($this->config->isEnabled($website) === false ||
            $this->config->isSubscriberOptInEnabled($website) === false ||
            $this->config->isSubscriberOptInCaptchaEnabled($website) === false ||
            $this->config->getSubscriberOptInCaptchaType($website) !== 'magento_captcha'
        ) {
            return '';
        }

        return parent::_toHtml();
    }
}
