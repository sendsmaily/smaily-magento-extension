<?php

namespace Smaily\SmailyForMagento\Model\Config\Source;

class CaptchaType implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Get options for CAPTCHA type field.
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => 'magento_captcha',
                'label' => 'Magento Built-In CAPTCHA',
            ],
            [
                'value' => 'google_captcha',
                'label' => 'Google reCAPTCHA',
            ],
        ];
    }
}
