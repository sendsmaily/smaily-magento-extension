<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Multilingual;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Store view -> ISO 639-1 language code (from the store locale).
 */
class LanguageResolver
{
    private const XML_PATH_LOCALE = 'general/locale/code';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function forStore(int|string|null $storeId): string
    {
        $locale = (string)$this->scopeConfig->getValue(
            self::XML_PATH_LOCALE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        $language = strtolower(strtok($locale, '_') ?: '');

        return preg_match('/^[a-z]{2,3}$/', $language) === 1 ? $language : '';
    }
}
