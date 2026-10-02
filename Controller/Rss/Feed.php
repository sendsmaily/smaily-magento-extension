<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Rss;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Rss\FeedBuilder;

/**
 * Public product RSS feed at smaily/rss/feed (legacy-compatible route).
 *
 * Query parameters: category (category ID), limit (1-250, default 50),
 * sort (created_at|updated_at|name|price), order (asc|desc). Standard
 * Magento store resolution applies, so per-store feeds use the store's
 * base URL or ?___store=.
 */
class Feed implements HttpGetActionInterface
{
    private const CACHE_LIFETIME_SECONDS = 900;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly FeedBuilder $feedBuilder,
        private readonly Config $config,
        private readonly \Magento\Framework\App\CacheInterface $cache
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): Raw
    {
        $result = $this->rawFactory->create();

        if (!$this->config->isRssEnabled()) {
            $result->setHttpResponseCode(404);
            $result->setContents('');

            return $result;
        }

        $categoryParam = $this->request->getParam('category');
        $categoryId = is_numeric($categoryParam) ? (int)$categoryParam : null;
        // A value that is not a single string (?limit[]=1) counts as absent.
        [$limit, $sort, $order] = FeedBuilder::normalize(
            (int)$this->stringParam('limit'),
            $this->stringParam('sort'),
            $this->stringParam('order')
        );
        $store = $this->storeManager->getStore();

        // Server-side cache: the feed is unauthenticated and rebuilding it
        // loads up to 250 products, so requests for the same feed must not hit
        // the DB — keyed by the normalized values, so varying an invalid
        // parameter cannot bypass the cache.
        $cacheKey = 'smaily_rss_' . sha1(implode('|', [
            (int)$store->getId(),
            (string)$categoryId,
            $limit,
            $sort,
            $order,
        ]));
        $xml = $this->cache->load($cacheKey);
        if ($xml === false) {
            $xml = $this->feedBuilder->build($store, $categoryId, $limit, $sort, $order);
            $this->cache->save($xml, $cacheKey, [], self::CACHE_LIFETIME_SECONDS);
        }

        $result->setHeader('Content-Type', 'application/rss+xml; charset=UTF-8', true);
        $result->setHeader(
            'Cache-Control',
            sprintf('public, max-age=%d', self::CACHE_LIFETIME_SECONDS),
            true
        );
        $result->setContents($xml);

        return $result;
    }

    /**
     * A query value as a string; one that is not a single string counts as absent.
     *
     * @param string $name
     * @return string
     */
    private function stringParam(string $name): string
    {
        $value = $this->request->getParam($name);

        return is_string($value) ? $value : '';
    }
}
