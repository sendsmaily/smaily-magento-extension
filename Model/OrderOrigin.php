<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

use Magento\Framework\App\Area;
use Magento\Framework\App\PageCache\FormKey;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Where the store's orders come from, for the Storefront URL field to open by
 * itself on a store that sells on a separate storefront (PRO-3660). Magento
 * does not store an order's origin, so each placed order stamps one of two
 * flags with the time (owner decision 2026-10-02):
 *
 * - an API order: placed through GraphQL, or through REST without Magento's
 *   own storefront session (no `form_key` cookie on the request);
 * - a storefront order: every other order — Luma's checkout places its order
 *   through REST from a Magento page, which carries the `form_key` cookie.
 *
 * The flags are installation-wide `smaily_connect_*` rows, removed by the
 * module's uninstall with the other flags.
 */
class OrderOrigin
{
    public const FLAG_LAST_STOREFRONT_ORDER = 'smaily_connect_last_storefront_order_at';
    public const FLAG_LAST_API_ORDER = 'smaily_connect_last_api_order_at';

    /** The window the Storefront URL field looks at. */
    public const WINDOW_SECONDS = 30 * 86400;

    public function __construct(
        private readonly State $appState,
        private readonly CookieManagerInterface $cookieManager,
        private readonly FlagManager $flagManager,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Stamp the order being placed in this request as an API or a storefront
     * order.
     */
    public function record(): void
    {
        $flag = $this->isApiRequest() ? self::FLAG_LAST_API_ORDER : self::FLAG_LAST_STOREFRONT_ORDER;
        $this->flagManager->saveFlag($flag, (int)$this->dateTime->gmtTimestamp());
    }

    /**
     * Whether the orders of the last 30 days came only through the API:
     * at least one API order and no storefront order. False until any order
     * has been seen.
     */
    public function isApiOnly(): bool
    {
        $since = (int)$this->dateTime->gmtTimestamp() - self::WINDOW_SECONDS;

        return (int)$this->flagManager->getFlagData(self::FLAG_LAST_API_ORDER) >= $since
            && (int)$this->flagManager->getFlagData(self::FLAG_LAST_STOREFRONT_ORDER) < $since;
    }

    private function isApiRequest(): bool
    {
        try {
            $area = $this->appState->getAreaCode();
        } catch (LocalizedException) {
            return false;
        }

        return $area === Area::AREA_GRAPHQL
            || ($area === Area::AREA_WEBAPI_REST
                && (string)$this->cookieManager->getCookie(FormKey::COOKIE_NAME) === '');
    }
}
