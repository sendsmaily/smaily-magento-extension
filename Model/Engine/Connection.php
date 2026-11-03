<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

/**
 * One Campaign Intelligence connection — one engine store — and the
 * websites it serves (CON-237). Engine\Connections builds them; a value,
 * never saved.
 *
 * The key names the connection wherever a row has to: 0 is the primary,
 * whose settings are at the default scope (every store before CON-237,
 * and every store run as one store); any other key is the id of the
 * website whose scope holds the connection's settings (its anchor
 * website). The websites it serves are those whose Smaily account is the
 * account the connection was made for, which need not include the anchor
 * website.
 */
class Connection
{
    public const PRIMARY_KEY = 0;

    /**
     * @param int[] $websiteIds the websites it serves, ascending
     * @param int[] $storeIds the store views of those websites, ascending
     * @param int|null $canonicalStoreId see canonicalStoreId()
     */
    public function __construct(
        private readonly int $key,
        private readonly Settings $settings,
        private readonly array $websiteIds,
        private readonly array $storeIds,
        private readonly ?int $canonicalStoreId,
        private readonly bool $servesAllWebsites
    ) {
    }

    /** 0 for the primary, else the id of the website whose scope holds its settings. */
    public function key(): int
    {
        return $this->key;
    }

    /** The connection's settings, bound to its scope. */
    public function settings(): Settings
    {
        return $this->settings;
    }

    /**
     * @return int[] the websites it serves, ascending
     */
    public function websiteIds(): array
    {
        return $this->websiteIds;
    }

    /**
     * @return int[] the store views of the websites it serves, ascending
     */
    public function storeIds(): array
    {
        return $this->storeIds;
    }

    /**
     * The store view whose scope its catalog is read at and whose id its
     * queue rows carry: the default store view of the installation's
     * default website when it serves that website, else the default store
     * view of the lowest-id website it serves; null when it serves no
     * website (a queue row then carries NULL, which means the primary).
     */
    public function canonicalStoreId(): ?int
    {
        return $this->canonicalStoreId;
    }

    /**
     * The website id its rows in the marketing queue (smaily_event_queue)
     * carry, which Connections::forWebsite() reads back: 0 for the primary
     * — the value every engine row carried before CON-237 — else the
     * lowest-id website it serves (0 when it serves none).
     */
    public function eventWebsiteId(): int
    {
        return $this->isPrimary() ? 0 : (int)($this->websiteIds[0] ?? 0);
    }

    public function isPrimary(): bool
    {
        return $this->key === self::PRIMARY_KEY;
    }

    /** Whether it serves every website of the installation (one store, or every website on its account). */
    public function servesAllWebsites(): bool
    {
        return $this->servesAllWebsites;
    }
}
