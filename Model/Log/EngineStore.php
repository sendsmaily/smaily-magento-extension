<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Log;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Log\Collection;

/**
 * The Campaign Intelligence connection a Log row belongs to (CON-237): an
 * engine ingest row by its store view (IngestQueue, NULL = the primary),
 * an engine row of the marketing queue (identity merge, profiling consent)
 * by its website (0 = the primary). Other marketing rows go to Smaily and
 * belong to none.
 *
 * For the Details panel it names the connection's engine store and the
 * websites it serves — only while the installation has more than one
 * connection; under one, the panel shows what it always showed.
 */
class EngineStore
{
    /** The marketing-queue event types that go to Campaign Intelligence. */
    private const ENGINE_EVENT_TYPES = [
        EventType::ENGINE_IDENTITY_MERGE,
        EventType::ENGINE_PROFILING_CONSENT,
    ];

    public function __construct(
        private readonly Connections $connections,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Whether the row goes to Campaign Intelligence.
     *
     * @param array<string, mixed> $row the row as QueueRowLoader reads it
     */
    public function isEngineRow(array $row): bool
    {
        $source = (string)($row['source'] ?? '');

        return $source === Collection::SOURCE_INTELLIGENCE
            || ($source === Collection::SOURCE_SMAILY
                && in_array((string)($row['type'] ?? ''), self::ENGINE_EVENT_TYPES, true));
    }

    /**
     * The connection the row goes to; null for a row that does not go to
     * Campaign Intelligence, or whose store view or website no connection
     * serves (separate stores only).
     *
     * @param array<string, mixed> $row the row as QueueRowLoader reads it
     */
    public function connectionOf(array $row): ?Connection
    {
        if (!$this->isEngineRow($row)) {
            return null;
        }
        if (($row['source'] ?? '') === Collection::SOURCE_INTELLIGENCE) {
            $storeId = $row['store_id'] ?? null;

            return $this->connections->forRowStoreId($storeId === null ? null : (int)$storeId);
        }

        return $this->connections->forWebsite((int)($row['website_id'] ?? 0));
    }

    /**
     * What the Details panel shows as the row's Campaign Intelligence
     * store: the engine store's name and the websites it serves, or that
     * no connection serves the row; null when the panel shows no such line
     * (one connection, or a row that does not go to Campaign Intelligence).
     *
     * @param array<string, mixed> $row the row as QueueRowLoader reads it
     */
    public function label(array $row): ?string
    {
        if (!$this->isEngineRow($row) || count($this->connections->all()) < 2) {
            return null;
        }
        $connection = $this->connectionOf($row);
        if ($connection === null) {
            return (string)__('None: no Campaign Intelligence store is connected for this row\'s website.');
        }

        $settings = $connection->settings();
        $name = $settings->getTenantName() !== '' ? $settings->getTenantName() : $settings->getTenantId();
        $websites = implode(', ', array_map(
            fn (int $websiteId): string => $this->websiteName($websiteId),
            $connection->websiteIds()
        ));
        if ($name === '') {
            return $websites;
        }

        return $websites === '' ? $name : sprintf('%s (%s)', $name, $websites);
    }

    private function websiteName(int $websiteId): string
    {
        try {
            return (string)$this->storeManager->getWebsite($websiteId)->getName();
        } catch (LocalizedException) {
            return (string)$websiteId;
        }
    }
}
