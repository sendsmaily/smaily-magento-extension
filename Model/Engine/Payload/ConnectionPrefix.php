<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Payload;

use Smaily\Connect\Model\Engine\Client;

/**
 * The connection's code prefix on the keys sent to the engine (contract
 * v1.33.0, CON-359): every product code (`sku`), parent product id
 * (`tags.product_id`, the §3b `product_ids`) and order id
 * (`external_order_id`, and the lines' `sku`) starts with the prefix the
 * engine gave the connection (Engine\Settings::getConnectionPrefix()), so
 * several shops in one engine store can number their products and orders
 * from 1 without overwriting each other. Customers carry no prefix.
 *
 * The keys are stored without it — on the queue rows, in the storefront's
 * pages — and get it when they are sent, from the connection that sends
 * them: here for the ingest items (Cron\FlushIngestQueue,
 * Engine\Client::relayBrowse()), in Cron\FlushIngestQueue for the §3b
 * ids and in Engine\CatalogManifest for the nightly list's skus. Never
 * twice, and a row queued before a connect goes out under the prefix of
 * the connection it reaches. A connection without a prefix sends every
 * key as it was stored.
 */
class ConnectionPrefix
{
    /** The prefix shape the engine assigns (contract v1.33.0 "Connection prefix"). */
    public const CONNECTION_PREFIX_PATTERN = '/^c[1-9][0-9]{0,3}-$/D';

    /**
     * One item of an ingest domain (§3, §5, §6) as it is sent: its keys
     * under the prefix. Customers (§4) go as they are.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function item(string $domain, array $item, string $prefix): array
    {
        if ($prefix === '') {
            return $item;
        }

        switch ($domain) {
            case Client::DOMAIN_CATALOG:
                $item = self::key($item, 'sku', $prefix);
                if (is_array($item['tags'] ?? null)) {
                    $item['tags'] = self::key($item['tags'], 'product_id', $prefix);
                }
                break;
            case Client::DOMAIN_ORDERS:
                $item = self::key($item, 'external_order_id', $prefix);
                if (is_array($item['items'] ?? null)) {
                    foreach ($item['items'] as $index => $line) {
                        if (is_array($line)) {
                            $item['items'][$index] = self::key($line, 'sku', $prefix);
                        }
                    }
                }
                break;
            case Client::DOMAIN_BROWSE:
                $item = self::key($item, 'sku', $prefix);
                break;
        }

        return $item;
    }

    /**
     * The `connection.prefix` of a setup exchange's or ping's `config`
     * block: '' when the block has no `connection` (an engine before
     * v1.33.0), the prefix when it is '' or `c<N>-`, null for anything else.
     *
     * @param array<string, mixed> $config
     */
    public static function configPrefix(array $config): ?string
    {
        if (!array_key_exists('connection', $config)) {
            return '';
        }
        $prefix = is_array($config['connection']) ? ($config['connection']['prefix'] ?? null) : null;

        return self::isConnectionPrefix($prefix) ? (string)$prefix : null;
    }

    /**
     * True for a prefix of the shape the engine assigns, and for ''.
     */
    public static function isConnectionPrefix(mixed $prefix): bool
    {
        return is_string($prefix) && ($prefix === '' || preg_match(self::CONNECTION_PREFIX_PATTERN, $prefix) === 1);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function key(array $item, string $field, string $prefix): array
    {
        $value = $item[$field] ?? null;
        if (is_scalar($value) && (string)$value !== '') {
            $item[$field] = $prefix . $value;
        }

        return $item;
    }
}
