<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

use Magento\Framework\App\Request\Http;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * With web server rewrites off, Magento adds the running script's name to
 * every link (`Store::_updatePathUseRewrites()`): `index.php` in a
 * storefront, admin or API request, but `magento` under bin/magento — where
 * cron builds the catalog import, the nightly re-sync, stock changes and
 * abandoned-cart reminders — and that link does not open. The storefront's
 * script is index.php, so a link built under another script name gets
 * index.php in its place (PRO-3731, PRO-3732). With rewrites on, the store's
 * link base has no script name and the link is left as it is.
 */
class StorefrontScript
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Http $request
    ) {
    }

    /**
     * The link with index.php where Magento put another running script's
     * name; unchanged when the link has none.
     */
    public function apply(string $url, int $storeId): string
    {
        $script = ltrim((string)strrchr('/' . (string)$this->request->getServerValue('SCRIPT_FILENAME'), '/'), '/');
        if ($script === '' || $script === 'index.php') {
            return $url;
        }

        $store = $this->storeManager->getStore($storeId);
        if (!$store instanceof Store) {
            return $url;
        }
        foreach ([false, true] as $secure) {
            $base = $store->getBaseUrl(UrlInterface::URL_TYPE_DIRECT_LINK, $secure);
            if (str_ends_with($base, '/' . $script . '/') && str_starts_with($url, $base)) {
                return substr($base, 0, -strlen($script . '/')) . 'index.php/' . substr($url, strlen($base));
            }
        }

        return $url;
    }
}
