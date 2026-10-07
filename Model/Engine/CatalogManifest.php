<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\FlagManager;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Logger\Logger;

/**
 * The nightly catalog manifest (contract v1.12.0 §3c, PRO-3854): once a
 * night (Cron\SendCatalogManifest) the store's complete product list goes
 * to the engine as `{sku, in_stock}` only. The engine tombstones each
 * product missing from it and takes its stock where it differs, so a lost
 * delete or stock change is healed within a night.
 *
 * The list is every product the catalog sync sends a row for, read at the
 * same canonical scope (CatalogProductLoader::loadForManifest()), keyed and
 * stocked by CatalogPayloadBuilder::manifestItem() — the catalog row's own
 * `sku` and `in_stock` — less the disabled products. It is built page by
 * page right before the send, keeping two values per product, and sent in
 * one request; it is never queued, so it is never older than the send. A
 * send that fails is not tried later: the next night builds a new list.
 *
 * A partial or premature list would remove real products, so nothing is
 * sent — only logged — while sending to the engine is not allowed (not
 * connected, or the remembered refusal), while the catalog import is queued
 * or running (unless nothing has moved it for STALLED_IMPORT_SECONDS, or the
 * backfill tick has set it aside — JobManager::isActiveAndMoving()), while
 * catalog rows, removals or stock-change markers still wait in the queue,
 * or when building the list fails. A store with more than MAX_PRODUCTS
 * enabled products sends nothing either, and gets a failed Log row saying
 * why. Every list sent is one Log row (domain catalog_manifest) holding the
 * engine's answer. The nights in a row no list went out, and why the last
 * one did not, are kept in a flag (FLAG_UNSENT) for the Dashboard
 * (PRO-3914).
 */
class CatalogManifest
{
    /**
     * The Log row of a nightly catalog manifest (§3c, PRO-3854). Never
     * queued for sending: send() sends the list itself and writes the row
     * with the outcome, so no flusher claims this domain.
     */
    public const DOMAIN = 'catalog_manifest';

    /** Contract §3c: at most this many items, in one request. */
    public const MAX_PRODUCTS = 50000;

    /**
     * The Log row of a store over MAX_PRODUCTS, stored as English source
     * text and shown in the admin's language (Log\FailureMessage).
     */
    public const TOO_MANY_PRODUCTS = 'Not sent: the store has more than 50,000 enabled products,'
        . ' the most the nightly product list can hold.';

    /** A catalog import that nothing has moved for this long no longer holds the list back. */
    public const STALLED_IMPORT_SECONDS = JobManager::STALLED_SECONDS;

    /**
     * The nights in a row no list went out and why the last one did not
     * (PRO-3914): {nights: int, reason: one of the REASON_* values}. A flag,
     * not a Log row: a skipped night sends nothing, so it has no exchange to
     * show, and the Dashboard needs only this one value. Deleted when a list
     * is sent, and while sending is not allowed — no list is due then, so a
     * new connection counts from its first night. Read by
     * ViewModel\Adminhtml\DashboardData through readUnsent().
     */
    public const FLAG_UNSENT = 'smaily_connect_catalog_manifest_unsent';

    /** The catalog import was queued or running and moving. */
    public const REASON_IMPORT = 'import';

    /** Catalog rows, removals or stock-change markers still waited in the queue. */
    public const REASON_QUEUE = 'queue';

    /** Building the list failed. */
    public const REASON_BUILD = 'build';

    /** The store has more than MAX_PRODUCTS enabled products. */
    public const REASON_TOO_MANY = 'too_many';

    /** The engine did not take the list (the failed Log row has its answer). */
    public const REASON_FAILED = 'failed';

    /** Sending to the engine is not allowed: no list is due. */
    private const REASON_NOT_ALLOWED = 'not_allowed';

    /** What the info log says for each night skipped before the list is built. */
    private const SKIP_LOG = [
        self::REASON_NOT_ALLOWED => 'Campaign Intelligence is not connected, or refuses this store',
        self::REASON_IMPORT => 'the catalog import is running or waiting to start',
        self::REASON_QUEUE => 'catalog changes still wait in the queue',
    ];

    /** Products read per page. */
    private const PAGE_SIZE = 1000;

    /** Items of the list the Log row keeps as a sample of what was sent. */
    private const LOGGED_ITEMS = 20;

    /** Queue domains whose undelivered rows are catalog changes on their way to the engine. */
    private const CATALOG_DOMAINS = [
        Client::DOMAIN_CATALOG,
        Client::DOMAIN_CATALOG_REMOVE,
        CatalogIngest::DOMAIN_CHANGED,
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly JobManager $jobManager,
        private readonly IngestQueue $queue,
        private readonly CatalogProductLoader $productLoader,
        private readonly CatalogPayloadBuilder $payloadBuilder,
        private readonly StockRegistryStorage $stockRegistryStorage,
        private readonly Client $client,
        private readonly Logger $logger,
        private readonly FlagManager $flagManager
    ) {
    }

    public function send(): void
    {
        $skip = $this->skipReason();
        if ($skip !== null) {
            $this->logger->info('Nightly catalog manifest not sent', ['reason' => self::SKIP_LOG[$skip]]);
            if ($skip === self::REASON_NOT_ALLOWED) {
                $this->clearUnsent();
            } else {
                $this->recordUnsent($skip);
            }

            return;
        }

        try {
            $products = $this->products();
        } catch (\Throwable $exception) {
            $this->logger->error('Nightly catalog manifest not sent: building the product list failed', [
                'error' => $exception->getMessage(),
            ]);
            $this->recordUnsent(self::REASON_BUILD);

            return;
        }

        if (count($products) > self::MAX_PRODUCTS) {
            $this->queue->markFailed(
                $this->queue->newEvent(self::DOMAIN, ['limit' => self::MAX_PRODUCTS]),
                self::TOO_MANY_PRODUCTS,
                true
            );
            $this->recordUnsent(self::REASON_TOO_MANY);

            return;
        }

        $event = $this->queue->newEvent(self::DOMAIN, [
            'products' => count($products),
            'in_stock' => count(array_filter(array_column($products, 'in_stock'))),
        ]);
        $error = null;
        try {
            $this->client->catalogManifest($products);
        } catch (EngineException $exception) {
            $error = $exception->getMessage();
        }

        $this->recordExchange($event, $products);
        if ($error === null) {
            $this->queue->markSent($event);
            $this->clearUnsent();
        } else {
            $this->queue->markFailed($event, $error, true);
            $this->recordUnsent(self::REASON_FAILED);
        }
    }

    /**
     * Why tonight's list must not be sent (a key of SKIP_LOG), or null when
     * it may.
     */
    private function skipReason(): ?string
    {
        if (!$this->settings->isSendingAllowed()) {
            return self::REASON_NOT_ALLOWED;
        }
        if ($this->jobManager->isActiveAndMoving(
            Job::TYPE_CATALOG,
            Job::TARGET_ENGINE,
            Job::ENGINE_WEBSITE_ID,
            self::STALLED_IMPORT_SECONDS
        )) {
            return self::REASON_IMPORT;
        }
        foreach (self::CATALOG_DOMAINS as $domain) {
            if ($this->queue->countPending($domain) > 0) {
                return self::REASON_QUEUE;
            }
        }

        return null;
    }

    /**
     * The nights in a row no list went out, and why the last one did not (a
     * REASON_* value; '' when none is recorded) — FLAG_UNSENT as the
     * Dashboard reads it.
     *
     * @return array{nights: int, reason: string}
     */
    public function readUnsent(): array
    {
        $unsent = $this->flagManager->getFlagData(self::FLAG_UNSENT);
        if (!is_array($unsent)) {
            return ['nights' => 0, 'reason' => ''];
        }

        return ['nights' => (int)($unsent['nights'] ?? 0), 'reason' => (string)($unsent['reason'] ?? '')];
    }

    /**
     * Count one more night without a list, and remember why (FLAG_UNSENT).
     */
    private function recordUnsent(string $reason): void
    {
        $this->flagManager->saveFlag(self::FLAG_UNSENT, [
            'nights' => $this->readUnsent()['nights'] + 1,
            'reason' => $reason,
        ]);
    }

    /**
     * Forget the nights without a list (FLAG_UNSENT): one went out, or none
     * is due.
     */
    private function clearUnsent(): void
    {
        $this->flagManager->deleteFlag(self::FLAG_UNSENT);
    }

    /**
     * The list, read in pages by entity id until a page comes back empty —
     * an early stop would leave products out, which the engine reads as
     * deleted. Stops one item past MAX_PRODUCTS, enough to tell the store
     * is over the limit.
     *
     * @return array<int, array{sku: string, in_stock: bool}>
     */
    private function products(): array
    {
        $products = [];
        $cursor = 0;
        do {
            $page = $this->productLoader->loadForManifest($cursor, self::PAGE_SIZE);
            foreach ($page as $product) {
                $cursor = max($cursor, (int)$product->getId());
                $item = $this->payloadBuilder->manifestItem($product);
                if ($item === null) {
                    continue;
                }
                $products[] = $item;
                if (count($products) > self::MAX_PRODUCTS) {
                    return $products;
                }
            }
            // The stock registry keeps every stock item it reads for the rest
            // of the process; the list needs none of them again.
            $this->stockRegistryStorage->clean();
        } while ($page !== []);

        return $products;
    }

    /**
     * Keep on the row, for the Log's Details, the start of the list as sent
     * and the engine's answer (removed, stock_fixed, missing_in_engine,
     * guard_tripped, …). The whole list would make the row as large as the
     * request.
     *
     * @param array<int, array{sku: string, in_stock: bool}> $products
     */
    private function recordExchange(IngestEvent $event, array $products): void
    {
        $exchange = $this->client->lastExchange();
        if ($exchange === null) {
            return;
        }

        $sent = ['products' => array_slice($products, 0, self::LOGGED_ITEMS)];
        if (count($products) > self::LOGGED_ITEMS) {
            $sent['products_not_shown'] = count($products) - self::LOGGED_ITEMS;
        }
        $this->queue->recordExchange($event, $sent, $exchange['response']);
    }
}
