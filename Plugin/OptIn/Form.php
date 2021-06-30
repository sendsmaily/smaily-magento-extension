<?php

namespace Smaily\SmailyForMagento\Plugin\OptIn;

use Smaily\SmailyForMagento\Helper\Config;

class Form
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
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        Config $config
    )
    {
        $this->storeManager = $storeManager;

        $this->config = $config;
    }

    /**
     * Modify form HTML before outputting to page.
     *
     * @param \Magento\Newsletter\Block\Subscribe $block
     * @param string $html
     * @access public
     * @return string
     */
    public function afterToHtml(\Magento\Newsletter\Block\Subscribe $block, $html)
    {
        $website = $this->storeManager->getWebsite();

        if (
            $this->config->isEnabled($website) === false ||
            $this->config->isSubscriberOptInEnabled($website) === false ||
            $this->config->isSubscriberOptInCaptchaEnabled($website) === false
        ) {
            return $html;
        }

        // Add CAPTCHA container to HTML.
        $container = $block->getChildHtml('smaily.smailyformagento.captcha');
        return preg_replace('/(.*)(<div\s[^>]*class="actions"[^>]*>.*)/siU', '\1' . $container . '\2', $html);
    }
}
