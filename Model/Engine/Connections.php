<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\StoreLayout;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * The Campaign Intelligence connections of the installation and the
 * websites each one serves (CON-237, phase 2 of CON-279). The engine store
 * follows the store: one store — in one version or several — is one engine
 * store, and each separate store connects its own. The engine holds one
 * engine store per Smaily account, so connections are grouped by the
 * account: websites of separate stores on one Smaily account share one
 * connection.
 *
 * - One store (StoreLayout::isSeparateStores() false): the primary serves
 *   every website, connected or not, and nothing else is read.
 * - Separate stores: a website is served by the connection made for its
 *   Smaily account (Settings::getSmailySubdomain() equals the website's
 *   normalized subdomain — its own account, in per-language accounts mode
 *   its fallback account, else the store-wide one); the primary takes part
 *   only while connected. A website no connection was made for is not
 *   connected (null). When two connections name the same account, the
 *   primary, else the lowest key, serves it.
 *
 * The primary (key 0) always exists; its settings are the DI Settings
 * (default scope) and its client the DI Client. A website connection is a
 * website that holds the mark Settings::XML_PATH_WEBSITE_CONNECTION at its
 * own scope; its settings are Settings::withWebsite() and its client one
 * of its own. Built once per request (reset() rebuilds).
 */
class Connections
{
    /** @var array<int, Connection>|null key => connection, primary first */
    private ?array $connections = null;

    /** @var array<int, int|null> website id => key of the connection serving it, null for none */
    private array $websiteKeys = [];

    /** @var array<int, int> store view id => website id */
    private array $storeWebsites = [];

    private bool $separate = false;

    /** @var array<int, Client> key => client */
    private array $clients = [];

    public function __construct(
        private readonly StoreLayout $storeLayout,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly SubdomainNormalizer $normalizer,
        private readonly Settings $settings,
        private readonly Client $client,
        private readonly ClientFactory $clientFactory
    ) {
    }

    /**
     * Every connection, the primary first, then the website connections by key.
     *
     * @return Connection[]
     */
    public function all(): array
    {
        return array_values($this->load());
    }

    public function primary(): Connection
    {
        return $this->load()[Connection::PRIMARY_KEY];
    }

    /**
     * The connection serving a website, or null when none does (separate
     * stores only). Website 0 — a row queued before CON-237 — is the
     * primary's.
     */
    public function forWebsite(int $websiteId): ?Connection
    {
        $connections = $this->load();
        if (!$this->separate || $websiteId === 0) {
            return $connections[Connection::PRIMARY_KEY];
        }
        $key = $this->websiteKeys[$websiteId] ?? null;

        return $key === null ? null : $connections[$key];
    }

    /**
     * The connection serving a store view's website, or null when none does
     * or the store view no longer exists (separate stores only). Store view
     * 0 is the primary's.
     */
    public function forStore(int $storeId): ?Connection
    {
        $this->load();
        if (!$this->separate || $storeId === 0) {
            return $this->primary();
        }
        $websiteId = $this->storeWebsites[$storeId] ?? null;

        return $websiteId === null ? null : $this->forWebsite($websiteId);
    }

    /**
     * The connection a queue row is for: NULL (every row queued before
     * CON-237) is the primary's, any other value is the store view whose
     * website the connection serves.
     */
    public function forRowStoreId(?int $storeId): ?Connection
    {
        return $storeId === null ? $this->primary() : $this->forStore($storeId);
    }

    /**
     * The connection with a key (Connection::key()), or null when there is
     * none — under one store only the primary is.
     */
    public function forKey(int $key): ?Connection
    {
        return $this->load()[$key] ?? null;
    }

    /** The engine client of a connection: the DI client for the primary, one of its own for any other. */
    public function client(Connection $connection): Client
    {
        if ($connection->isPrimary()) {
            return $this->client;
        }

        return $this->clients[$connection->key()] ??= $this->clientFactory->create([
            'settings' => $connection->settings(),
        ]);
    }

    /**
     * The Smaily account a website sends with, as a normalized subdomain:
     * its own, in per-language accounts mode its fallback account, else the
     * store-wide one; '' when none is saved.
     */
    public function accountOf(int $websiteId): string
    {
        return $this->normalizer->normalize($this->config->getWebsiteSubdomain($websiteId));
    }

    /**
     * The Smaily account of the installation-wide connection: the
     * store-wide account, else the default website's; '' when neither is
     * saved.
     */
    public function installationAccount(): string
    {
        $account = $this->accountOf(0);
        if ($account !== '') {
            return $account;
        }
        $defaultWebsiteId = $this->defaultWebsiteId();

        return $defaultWebsiteId === null ? '' : $this->accountOf($defaultWebsiteId);
    }

    /** Forget what was read, for a change made in this request (a connect or a disconnect). */
    public function reset(): void
    {
        $this->connections = null;
        $this->websiteKeys = [];
        $this->storeWebsites = [];
        $this->clients = [];
    }

    /**
     * @return array<int, Connection>
     */
    private function load(): array
    {
        if ($this->connections !== null) {
            return $this->connections;
        }
        $websiteIds = $this->websiteIds();
        $storesByWebsite = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            $websiteId = (int)$store->getWebsiteId();
            $this->storeWebsites[$storeId] = $websiteId;
            $storesByWebsite[$websiteId][] = $storeId;
        }
        $this->separate = $this->storeLayout->isSeparateStores();

        $settings = [Connection::PRIMARY_KEY => $this->settings];
        if (!$this->separate) {
            $served = [Connection::PRIMARY_KEY => $websiteIds];
        } else {
            $owners = [];
            if ($this->settings->isConnected()) {
                $this->claim($owners, $this->settings, Connection::PRIMARY_KEY);
            }
            foreach ($websiteIds as $websiteId) {
                $own = $this->settings->withWebsite($websiteId);
                if ($own->isWebsiteConnection()) {
                    $settings[$websiteId] = $own;
                    $this->claim($owners, $own, $websiteId);
                }
            }
            $served = array_fill_keys(array_keys($settings), []);
            foreach ($websiteIds as $websiteId) {
                $account = $this->accountOf($websiteId);
                $key = $account === '' ? null : ($owners[$account] ?? null);
                $this->websiteKeys[$websiteId] = $key;
                if ($key !== null) {
                    $served[$key][] = $websiteId;
                }
            }
        }

        $this->connections = [];
        foreach ($settings as $key => $connectionSettings) {
            $storeIds = [];
            foreach ($served[$key] as $websiteId) {
                array_push($storeIds, ...($storesByWebsite[$websiteId] ?? []));
            }
            sort($storeIds);
            $this->connections[$key] = new Connection(
                $key,
                $connectionSettings,
                $served[$key],
                $storeIds,
                $this->canonicalStoreId($served[$key], $storesByWebsite),
                $served[$key] !== [] && $served[$key] === $websiteIds
            );
        }

        return $this->connections;
    }

    /**
     * @param array<string, int> $owners account => key; the first claim of an account wins
     */
    private function claim(array &$owners, Settings $settings, int $key): void
    {
        $account = $this->normalizer->normalize($settings->getSmailySubdomain());
        if ($account !== '' && !isset($owners[$account])) {
            $owners[$account] = $key;
        }
    }

    /**
     * @param int[] $websiteIds ascending
     * @param array<int, int[]> $storesByWebsite
     */
    private function canonicalStoreId(array $websiteIds, array $storesByWebsite): ?int
    {
        if ($websiteIds === []) {
            return null;
        }
        try {
            $defaultStore = $this->storeManager->getDefaultStoreView();
        } catch (LocalizedException) {
            $defaultStore = null;
        }
        if ($defaultStore !== null && in_array((int)$defaultStore->getWebsiteId(), $websiteIds, true)) {
            return (int)$defaultStore->getId();
        }
        foreach ($websiteIds as $websiteId) {
            try {
                $groupId = (string)$this->storeManager->getWebsite($websiteId)->getDefaultGroupId();
                $storeId = (int)$this->storeManager->getGroup($groupId)->getDefaultStoreId();
            } catch (LocalizedException) {
                $storeId = 0;
            }
            if ($storeId > 0) {
                return $storeId;
            }
            $stores = $storesByWebsite[$websiteId] ?? [];
            if ($stores !== []) {
                return min($stores);
            }
        }

        return null;
    }

    /**
     * @return int[] ascending
     */
    private function websiteIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $ids[] = (int)$website->getId();
        }
        sort($ids);

        return $ids;
    }

    private function defaultWebsiteId(): ?int
    {
        try {
            $id = (int)($this->storeManager->getDefaultStoreView()?->getWebsiteId() ?? 0);
        } catch (LocalizedException) {
            return null;
        }

        return $id > 0 ? $id : null;
    }
}
