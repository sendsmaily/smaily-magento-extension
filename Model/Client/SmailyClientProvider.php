<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client;

use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Config;

/**
 * Builds scope-bound Smaily API clients from configuration.
 *
 * Store-view granularity supports per-language Smaily accounts
 * (multilingual mode A); most stores resolve to the website/default value.
 */
class SmailyClientProvider
{
    public function __construct(
        private readonly Config $config,
        private readonly SmailyClientFactory $clientFactory
    ) {
    }

    /**
     * Get a client for the given store view scope.
     *
     * @param int|string|null $storeId
     * @throws SmailyClientException when credentials are not configured
     */
    public function forStore(int|string|null $storeId = null): SmailyClient
    {
        if (!$this->config->isConnected($storeId)) {
            throw new SmailyClientException(
                __('Smaily API credentials are not configured (store scope: %1)', $storeId ?? 'default')
            );
        }

        return $this->clientFactory->create([
            'subdomain' => $this->config->getSubdomain($storeId),
            'username' => $this->config->getUsername($storeId),
            'password' => $this->config->getPassword($storeId),
        ]);
    }

    /**
     * Get a client for the Smaily account saved for a website: its own, else
     * the store-wide one (CON-189). In per-language accounts mode it is the
     * default fallback account, saved by the same save. Website 0 reads the
     * store-wide account.
     *
     * @throws SmailyClientException when credentials are not configured
     */
    public function forWebsite(int $websiteId): SmailyClient
    {
        $subdomain = $this->config->getWebsiteSubdomain($websiteId);
        $username = $this->config->getWebsiteUsername($websiteId);
        $password = $this->config->getWebsitePassword($websiteId);
        if ($subdomain === '' || $username === '' || $password === '') {
            throw new SmailyClientException(
                __('Smaily API credentials are not configured (website: %1)', $websiteId)
            );
        }

        return $this->clientFactory->create([
            'subdomain' => $subdomain,
            'username' => $username,
            'password' => $password,
        ]);
    }

    /**
     * Get a client for the transactional-email account saved for a website
     * (its own, else the one saved for all websites; PRO-4062), or for the
     * one saved for all websites (null).
     *
     * @throws SmailyClientException when that account is not configured
     */
    public function forTransactional(?int $websiteId): SmailyClient
    {
        $subdomain = $this->config->getTransactionalSubdomain($websiteId);
        $username = $this->config->getTransactionalUsername($websiteId);
        $password = $this->config->getTransactionalPassword($websiteId);
        if ($subdomain === '' || $username === '' || $password === '') {
            throw new SmailyClientException(__('The transactional-email account is not configured.'));
        }

        return $this->clientFactory->create([
            'subdomain' => $subdomain,
            'username' => $username,
            'password' => $password,
        ]);
    }
}
