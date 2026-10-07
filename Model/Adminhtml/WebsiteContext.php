<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;

/**
 * The website the admin surfaces (wizard + Settings) currently target
 * (RFC_MULTI_WEBSITE.md §2) — the single seam every save/prefill path reads
 * to know which website's scope to use.
 *
 * A single-website install always resolves its one real website, exactly as
 * before Phase 2. On a 2+-website install, the Settings page's own website
 * selector and the wizard's website-chooser step both work by reloading with
 * a `?website=<id>` request param, which this class reads (falling back to
 * the installation's default website when absent, invalid, or pointing at a
 * website that no longer exists).
 */
class WebsiteContext
{
    /** @var array<int, string>|null */
    private ?array $websiteOptions = null;

    private bool $requestedResolved = false;

    private ?int $requestedId = null;

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request
    ) {
    }

    public function getWebsiteId(): int
    {
        return $this->requestedWebsiteId() ?? $this->defaultWebsiteId();
    }

    /**
     * Whether the current request explicitly named a (real) website, as
     * opposed to falling back to the installation's default one.
     */
    public function isExplicit(): bool
    {
        return $this->requestedWebsiteId() !== null;
    }

    /**
     * The resolved website's own default store — the canonical scope for
     * store-view-scoped reads (subdomain/username/password) that fall back
     * through website to default.
     */
    public function getStoreId(): int
    {
        try {
            $website = $this->storeManager->getWebsite($this->getWebsiteId());
        } catch (NoSuchEntityException) {
            return 0;
        }

        return $website instanceof Website ? (int)($website->getDefaultStore()?->getId() ?? 0) : 0;
    }

    /**
     * Whether this install has more than one real website — the selector
     * (Settings) and the chooser step (wizard) only ever render when true.
     */
    public function hasMultipleWebsites(): bool
    {
        return count($this->getWebsiteOptions()) > 1;
    }

    /**
     * Every real website, id => name, for the selector/chooser controls.
     *
     * @return array<int, string>
     */
    public function getWebsiteOptions(): array
    {
        if ($this->websiteOptions === null) {
            $this->websiteOptions = [];
            foreach ($this->storeManager->getWebsites() as $website) {
                $this->websiteOptions[(int)$website->getId()] = (string)$website->getName();
            }
        }

        return $this->websiteOptions;
    }

    /**
     * The `website` request param, validated against real websites — null
     * when absent, blank, or naming a website that doesn't (or no longer)
     * exist.
     */
    private function requestedWebsiteId(): ?int
    {
        if (!$this->requestedResolved) {
            $this->requestedResolved = true;
            $requested = $this->request->getParam('website');
            if ($requested !== null && $requested !== '') {
                $id = (int)$requested;
                $this->requestedId = isset($this->getWebsiteOptions()[$id]) ? $id : null;
            }
        }

        return $this->requestedId;
    }

    private function defaultWebsiteId(): int
    {
        try {
            return (int)($this->storeManager->getDefaultStoreView()?->getWebsiteId() ?? 0);
        } catch (NoSuchEntityException) {
            return 0;
        }
    }
}
