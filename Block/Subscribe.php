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
    public function isCaptchaEnabled()
    {
        return $this->helper->isCaptchaEnabled();
    }

    /**
     * Check if collection newsletter subscribers is enabled.
     *
     * @return boolean
     */
    public function isNewsletterSubscriptionEnabled()
    {
        return $this->helper->isNewsletterSubscriptionEnabled();
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
     * Get newsletter template with CAPTCHA section.
     * May return empty section when subscribers collection is enabled, but built in CAPTCHA is disabled.
     * In that case, an empty section is shown  instead of newsletter form, to prevent bots from poluting
     * customer db in Smaily.
     *
     * @return string HTML of newsletter template with CAPTCHA.
     */
    public function getTemplateWithCaptcha()
    {
        // Get original template.
        $originalTemlate = $this->getOriginalTemplate()->toHtml();
        $originalDOM = new \DOMDocument();
        $originalDOM->loadHTML($originalTemlate, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xPath = new \DOMXPath($originalDOM);

        $captchaType = $this->helper->getCaptchaType();

        if ($captchaType === 'google_captcha') {
            // JS will render reCAPTCHA block.
            return $this->getOriginalTemplate();
        } else {
            // May return empty string if built-in captcha is disabled.
            $captchaTemplate = $this->getBlockHtml('smaily.captcha');
        }

        // Only CAPTCHA section or error message when CAPTCHA is enabled, but disabled form general settings.
        if ($captchaTemplate) {
            // Remove newsletter class (keep only block class) from original form as it messes up CSS.
            $newsletterClass = $xPath->query('//div[@class="block newsletter"]')->item(0);
            $newsletterClass->attributes->getNamedItem('class')->nodeValue = 'block';
             // Select form and action section.
            $form = $originalDOM->getElementsByTagName('form')->item(0);
            $actionsSection = $xPath->query('//div[@class="actions"]')->item(0);
            // Add CAPTCHA section before action section.
            $captcha = $originalDOM->createDocumentFragment();
            $captcha->appendXML($captchaTemplate);
            $form->insertBefore($captcha, $actionsSection);
            return $originalDOM->saveHTML();
        } else {
            return '<div style="color:red;"><p>You have enabled Smaily Newsletter form with Magento built-in CAPTCHA,
            but the CAPTCHA is disabled in general settings.</p><p>Please enable magento CAPTCHA for Newsletter form
            in general settings!</p></div>';
        }
    }
}
