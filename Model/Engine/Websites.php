<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Customer\Model\Config\Share;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The store's websites as Campaign Intelligence names them (contract v1.21.0,
 * CON-253): one engine store serves every website of the installation, and
 * the customer, order and storefront rows name the website by its Magento
 * code; the setup exchange and the history import's `started` call report
 * how Magento shares customer accounts between websites (`store_config`).
 * Sent on a store with one website too: the fields are optional, and the
 * same rows go out whatever the number of websites.
 */
class Websites
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_WEBSITE = 'website';

    /** The longest website code the engine accepts (contract "Website code"). */
    private const MAX_CODE_LENGTH = 64;

    /** @var array<int, string|null> website id -> its code, memoized */
    private array $codes = [];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * `website` when Magento keeps a customer account per website
     * (Customer Configuration > Account Sharing Options > Share Customer
     * Accounts: Per Website, Magento's default), else `global`.
     */
    public function customerAccountScope(): string
    {
        return (int)$this->scopeConfig->getValue(Share::XML_PATH_CUSTOMER_ACCOUNT_SHARE) === Share::SHARE_WEBSITE
            ? self::SCOPE_WEBSITE
            : self::SCOPE_GLOBAL;
    }

    /**
     * The `store_config` of the setup exchange (§1) and of the history
     * import's `started` call (§25).
     *
     * @return array{customer_account_scope: string}
     */
    public function storeConfig(): array
    {
        return ['customer_account_scope' => $this->customerAccountScope()];
    }

    /**
     * A website's code, or null for the admin website, a website that no
     * longer exists, and a code the engine would not accept.
     */
    public function code(int $websiteId): ?string
    {
        if ($websiteId <= 0) {
            return null;
        }
        if (!array_key_exists($websiteId, $this->codes)) {
            try {
                $code = (string)$this->storeManager->getWebsite($websiteId)->getCode();
            } catch (LocalizedException) {
                $code = '';
            }
            $this->codes[$websiteId] = $code !== '' && strlen($code) <= self::MAX_CODE_LENGTH ? $code : null;
        }

        return $this->codes[$websiteId];
    }

    /**
     * The code of a store view's website, or null when the store view no
     * longer exists (an order keeps the id of a deleted store view).
     */
    public function codeOfStore(int $storeId): ?string
    {
        if ($storeId <= 0) {
            return null;
        }
        try {
            $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        } catch (LocalizedException) {
            return null;
        }

        return $this->code($websiteId);
    }

    /**
     * The code of the website the current request runs on.
     */
    public function current(): ?string
    {
        try {
            $websiteId = (int)$this->storeManager->getStore()->getWebsiteId();
        } catch (LocalizedException) {
            return null;
        }

        return $this->code($websiteId);
    }
}
