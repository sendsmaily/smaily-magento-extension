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
 * The website the admin surfaces (initial setup + Settings) currently target
 * — the single seam every save/prefill path reads to know which scope to use.
 *
 * The initial setup sets the whole store up (CON-179): its page marks the
 * request store-wide (useStoreWide()) and its calls carry `?website=0`. A
 * store-wide request targets the default scope: getWebsiteId() is
 * STORE_WIDE (0) on a 2+-website install, and a read at website 0 (the
 * admin website, which holds no rows of its own) resolves the default
 * scope; on a single-website install it is the one real website, whose
 * values are the store's.
 *
 * Otherwise a single-website install always resolves its one real website.
 * On a 2+-website install, the Settings page's website selector reloads with
 * a `?website=<id>` request param, which this class reads (falling back to
 * the installation's default website when absent, invalid, or pointing at a
 * website that no longer exists).
 */
class WebsiteContext
{
    /** The website id of a store-wide request on a 2+-website install: the default scope. */
    public const STORE_WIDE = 0;

    private bool $storeWide = false;

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
        if ($this->isStoreWide()) {
            return $this->hasMultipleWebsites() ? self::STORE_WIDE : $this->defaultWebsiteId();
        }

        return $this->requestedWebsiteId() ?? $this->defaultWebsiteId();
    }

    /**
     * Marks this request store-wide: the initial setup page sets the whole
     * store up (CON-179).
     */
    public function useStoreWide(): void
    {
        $this->storeWide = true;
    }

    /**
     * Whether this request targets the whole store (the default scope): the
     * initial setup page, or a call that carries `?website=0`.
     */
    public function isStoreWide(): bool
    {
        return $this->storeWide || (string)$this->request->getParam('website') === (string)self::STORE_WIDE;
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
        // Store 0 (admin) reads the default scope, as website 0 does.
        if ($this->getWebsiteId() === self::STORE_WIDE) {
            return 0;
        }
        try {
            $website = $this->storeManager->getWebsite($this->getWebsiteId());
        } catch (NoSuchEntityException) {
            return 0;
        }

        return $website instanceof Website ? (int)($website->getDefaultStore()?->getId() ?? 0) : 0;
    }

    /**
     * Whether this install has more than one real website — the Settings
     * selector and the website exceptions only ever render when true.
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
