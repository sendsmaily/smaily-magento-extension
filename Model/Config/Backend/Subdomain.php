<?php

namespace Smaily\SmailyForMagento\Model\Config\Backend;

class Subdomain extends \Magento\Framework\App\Config\Value
{
    /**
     * Clean up value before storing in database.
     *
     * @access public
     * @return void
     */
    public function beforeSave()
    {
        $subdomain = $this->getValue();

        // Normalize subdomain.
        // First, try to parse as full URL. If that fails, try to parse as subdomain.sendsmaily.net, and
        // if all else fails, then clean up subdomain and pass as is.
        if (filter_var($subdomain, FILTER_VALIDATE_URL)) {
            $url = parse_url($subdomain);
            $parts = explode('.', $url['host']);
            $subdomain = count($parts) >= 3 ? $parts[0] : '';
        } elseif (preg_match('/^[^\.]+\.sendsmaily\.net$/', $subdomain)) {
            $parts = explode('.', $subdomain);
            $subdomain = $parts[0];
        }

        $subdomain = preg_replace('/[^a-zA-Z0-9]+/', '', $subdomain);

        if (empty($subdomain)) {
            throw new \Magento\Framework\Exception\ValidatorException(__('Subdomain is required.'));
        }

        $this->setValue($subdomain);

        // Call parent method.
        parent::beforeSave();
    }
}
