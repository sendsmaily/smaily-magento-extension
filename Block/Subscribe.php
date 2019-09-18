<?php

namespace Smaily\SmailyForMagento\Block;

use \Magento\Framework\View\Element\Template\Context;
use Smaily\SmailyForMagento\Helper\Data as Helper;

class Subscribe extends \Magento\Newsletter\Block\Subscribe
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
     * Get Smaily module enabled status.
     *
     * @return boolean
     */
    public function isSmailyEnabled()
    {
        return $this->helper->isEnabled();
    }

    /**
     * Get original Magetno newsletter template in HTML string format.
     *
     * @return string HTML of original newsletter template.
     */
    public function originalTemplateHtml()
    {
        return $this->getOriginalTemplate()->toHtml();
    }

    /**
     * Gets original Magento Newsletter template.
     *
     * @return \Magento\Framework\View\Element\Template Original newsletter template.
     */
    public function getOriginalTemplate()
    {
        return $this->
                getLayout()->
                createBlock("Magento\Newsletter\Block\Subscribe")->
                setTemplate('Magento_Newsletter::subscribe.phtml');
    }

    /**
     * Get newsletter template with capcha section.
     *
     * @return string HTML of newsletter template with captcha.
     */
    public function getTemplateWithCaptcha()
    {
        // Get original template.
        $originalTemlate = $this->getOriginalTemplate()->toHtml();
        $originalDOM = new \DOMDocument();
        $originalDOM->loadHTML($originalTemlate);

        // Get captcha form. Visible when capcha is required.
        $captchaTemplate = $this->getBlockHtml('smaily.captcha');
        if (!empty($captchaTemplate)) {
            // Select form and action section.
            $form = $originalDOM->getElementsByTagName('form')->item(0);
            $xPath = new \DOMXPath($originalDOM);
            $actionsSection = $xPath->query('//div[@class="actions"]')->item(0);

            // Add capcha section before action section.
            $captcha = $originalDOM->createDocumentFragment();
            $captcha->appendXML($captchaTemplate);
            $form->insertBefore($captcha, $actionsSection);

            // Remove newsletter class (keep only block class) from original form as it messes up css.
            $newsletterClass = $xPath->query('//div[@class="block newsletter"]')->item(0);
            $newsletterClass->attributes->getNamedItem('class')->nodeValue = 'block';
        }

        return $originalDOM->saveHTML();
    }
}
