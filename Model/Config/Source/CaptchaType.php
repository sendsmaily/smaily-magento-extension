<?php

namespace Smaily\SmailyForMagento\Model\Config\Source;

class CaptchaType implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Get Option values for CAPTCHA type field.
     *
     * @return array
     */
    public function toOptionArray()
    {
        $list = [
            [
                'value' => 'magento_captcha',
                'label' => 'Magento Built-In CAPTCHA',
            ],
            [
                'value' => 'google_captcha',
                'label' => 'Google reCAPTCHA',
            ],
        ];

        return $list;
    }
}
