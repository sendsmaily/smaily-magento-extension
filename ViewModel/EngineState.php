<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel;

use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\Settings;

/**
 * Storefront view model for the attribution, browse-tracking and
 * recommendations scripts.
 */
class EngineState implements ArgumentInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AttributionManager $attributionManager,
        private readonly CookieHelper $cookieHelper,
        private readonly UrlInterface $urlBuilder,
        private readonly Json $serializer,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isAttributionActive(): bool
    {
        return $this->settings->isConnected();
    }

    public function isBrowseTrackingActive(): bool
    {
        return $this->settings->isBrowseTrackingEnabled();
    }

    /**
     * The recommendations script loads only where the engine may be called;
     * the widget's container renders under the same gate.
     */
    public function isRecommendationsActive(): bool
    {
        return $this->settings->isSendingAllowed();
    }

    public function getAttributionConfigJson(): string
    {
        return $this->serializer->serialize($this->attributionManager->getClientConfig());
    }

    public function getTrackerConfigJson(): string
    {
        return $this->serializer->serialize(
            ['relayUrl' => $this->urlBuilder->getUrl('smaily/relay')]
            + $this->consentConfig()
            + ['attribution' => $this->attributionManager->getClientConfig()]
        );
    }

    /**
     * The recommendations script's config: the store route it asks and what
     * it reads consent from, as the tracker does.
     */
    public function getRecommendationsConfigJson(): string
    {
        return $this->serializer->serialize(
            ['url' => $this->urlBuilder->getUrl('smaily/recommendations')] + $this->consentConfig()
        );
    }

    /**
     * What the storefront scripts read the shopper's consent from
     * (js/consent.js).
     *
     * @return array{cookieRestriction: bool, websiteId: int}
     */
    private function consentConfig(): array
    {
        return [
            // Cast deliberately: the helper is annotated @return bool but
            // actually returns the raw config value — the string "0" when
            // restriction mode is off, which is truthy in JS and would make
            // the scripts wait for a cookie notice the store never shows.
            'cookieRestriction' => (bool)$this->cookieHelper->isCookieRestrictionModeEnabled(),
            // The cookie notice's cookie lists the websites the shopper
            // accepted on; the scripts read it for this website only.
            'websiteId' => (int)$this->storeManager->getWebsite()->getId(),
        ];
    }
}
