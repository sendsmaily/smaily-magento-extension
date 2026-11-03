<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Support;

use PHPUnit\Framework\MockObject\MockObject;
use Smaily\Connect\Model\Backfill\EngineJobConnection;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Engine\Connections for a test (CON-237): the connections it is handed,
 * each with its own client, routed as the real registry routes them — a
 * queue row's NULL store view and website 0 to the primary, a store view
 * or website to the connection that serves it.
 *
 * For a PHPUnit TestCase (unit or integration).
 */
trait EngineConnectionsStub
{
    /**
     * One store: the primary serves every website (1, store view 1).
     */
    private function oneEngineConnection(
        Settings $settings,
        ?Client $client = null,
        ?int $canonicalStoreId = 1
    ): Connections&MockObject {
        return $this->engineConnections([
            [$this->engineConnection(Connection::PRIMARY_KEY, $settings, [1], [1], $canonicalStoreId, true), $client],
        ]);
    }

    /**
     * The connection of an engine import's job (Backfill\EngineJobConnection)
     * read from these connections.
     */
    private function engineJobConnection(Connections $connections, ?JobManager $jobManager = null): EngineJobConnection
    {
        return new EngineJobConnection(
            $connections,
            $jobManager ?? $this->createMock(JobManager::class),
            $this->createMock(Logger::class)
        );
    }

    /**
     * @param int[] $websiteIds
     * @param int[] $storeIds
     */
    private function engineConnection(
        int $key,
        Settings $settings,
        array $websiteIds,
        array $storeIds,
        ?int $canonicalStoreId,
        bool $servesAllWebsites = false
    ): Connection {
        return new Connection($key, $settings, $websiteIds, $storeIds, $canonicalStoreId, $servesAllWebsites);
    }

    /**
     * @param array<int, array{0: Connection, 1: Client|null}> $connections the primary first
     */
    private function engineConnections(array $connections): Connections&MockObject
    {
        $byKey = [];
        $clients = [];
        foreach ($connections as [$connection, $client]) {
            $byKey[$connection->key()] = $connection;
            $clients[$connection->key()] = $client ?? $this->createMock(Client::class);
        }
        $primary = $byKey[Connection::PRIMARY_KEY];
        $forStore = static function (int $storeId) use ($byKey, $primary): ?Connection {
            if ($storeId === 0) {
                return $primary;
            }
            foreach ($byKey as $connection) {
                if ($connection->servesAllWebsites() || in_array($storeId, $connection->storeIds(), true)) {
                    return $connection;
                }
            }

            return null;
        };
        $forWebsite = static function (int $websiteId) use ($byKey, $primary): ?Connection {
            if ($websiteId === 0) {
                return $primary;
            }
            foreach ($byKey as $connection) {
                if ($connection->servesAllWebsites() || in_array($websiteId, $connection->websiteIds(), true)) {
                    return $connection;
                }
            }

            return null;
        };

        $stub = $this->createMock(Connections::class);
        $stub->method('all')->willReturn(array_values($byKey));
        $stub->method('primary')->willReturn($primary);
        $stub->method('forKey')->willReturnCallback(static fn (int $key): ?Connection => $byKey[$key] ?? null);
        $stub->method('forStore')->willReturnCallback($forStore);
        $stub->method('forWebsite')->willReturnCallback($forWebsite);
        $stub->method('forRowStoreId')->willReturnCallback(
            static fn (?int $storeId): ?Connection => $storeId === null ? $primary : $forStore($storeId)
        );
        $stub->method('client')->willReturnCallback(
            static fn (Connection $connection): Client => $clients[$connection->key()]
        );
        $connected = static fn (): array => array_values(array_filter(
            $byKey,
            static fn (Connection $connection): bool => $connection->settings()->isConnected()
        ));
        $stub->method('connected')->willReturnCallback($connected);
        $stub->method('anyConnected')->willReturnCallback(static fn (): bool => $connected() !== []);

        return $stub;
    }
}
