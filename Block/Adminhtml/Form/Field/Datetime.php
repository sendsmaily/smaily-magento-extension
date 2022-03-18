<?php

namespace Smaily\SmailyForMagento\Block\Adminhtml\Form\Field;

class Datetime extends \Magento\Config\Block\System\Config\Form\Field
{
    protected $localeDate;

    /**
     * Class constructor.
     *
     * @access public
     * @return void
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        array $data = [],
        ?\Magento\Framework\View\Helper\SecureHtmlRenderer $secureRenderer = null
    ) {
        $this->localeDate = $context->getLocaleDate();
        parent::__construct($context, $data, $secureRenderer);
    }

    /**
     * Retrieve HTML markup for given form element.
     *
     * @param \Magento\Framework\Data\Form\Element\AbstractElement $element
     * @access public
     * @return string
     */
    public function render(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        // Set date and time formatting.
        $element->setDateFormat(\Magento\Framework\Stdlib\DateTime::DATE_INTERNAL_FORMAT);
        $element->setTimeFormat("HH:mm:ss");

        // Convert time to local timezone.
        $value = $element->getValue();
        if (!empty($value)) {
            $tz = new \DateTimeZone($this->localeDate->getConfigTimezone($element->getScope(), $element->getScopeId()));
            $dt = new \DateTime($value, new \DateTimeZone('UTC'));

            $element->setValue($dt->setTimezone($tz));
        }

        // Call parent class method.
        return parent::render($element);
    }
}
