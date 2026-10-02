<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Cron;

use Magento\Framework\Serialize\Serializer\Json;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Drains the engine ingest queue, one batch per domain per run.
 *
 * D6 responses are per-item: errors[].index maps back onto the batch rows,
 * so a 200 never marks the whole batch sent blindly (contract scar #5).
 * Transport failures (429 after retries, 5xx, network) reschedule the batch
 * with backoff; per-item validation errors are terminal for that row.
 *
 * The catalog_remove domain (§3b product-level removal, PRO-1231) is NOT
 * D6 and gets its own flush path: on a 2xx every batched id was applied —
 * an id in `not_found` is a contract-defined success ("already removed, or
 * never sent"), recorded as the row's outcome, never retried.
 */
class FlushIngestQueue
{
    public function __construct(
        private readonly Settings $settings,
        private readonly IngestQueue $queue,
        private readonly Client $client,
        private readonly Json $serializer,
        private readonly Logger $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->settings->isSendingAllowed()) {
            return;
        }

        $this->queue->requeueStale();

        foreach (array_keys(Client::DOMAIN_WRAPPERS) as $domain) {
            $this->flushDomain($domain);
        }

        $this->flushCatalogRemove();
    }

    private function flushDomain(string $domain): void
    {
        // Re-asked per domain: a refusal met by the first batch stops the
        // rest of this run instead of collecting four more 403s. Only the
        // refusal can appear mid-run — execute() proved connectedness.
        if ($this->settings->isRefused()) {
            return;
        }

        $events = $this->queue->claimBatch($domain, Client::DOMAIN_BATCH_LIMITS[$domain]);
        if (!$events) {
            return;
        }

        $items = [];
        foreach ($events as $index => $event) {
            $items[$index] = $this->queue->decodePayload($event);
        }

        try {
            $response = $this->client->ingest($domain, $items);
        } catch (EngineTransportException $exception) {
            $this->recordBatchExchange($domain, $events, $items);
            foreach ($events as $event) {
                $this->queue->markFailed($event, $exception->getMessage());
            }
            $this->logger->info('Ingest batch rescheduled', [
                'domain' => $domain,
                'count' => count($events),
                'error' => $exception->getMessage(),
            ]);

            return;
        } catch (EngineRequestException $exception) {
            if ($this->releaseIfRefused($events)) {
                return;
            }

            // Whole-batch 4xx: the request shape is wrong; retrying the same
            // rows cannot succeed.
            $this->recordBatchExchange($domain, $events, $items);
            foreach ($events as $event) {
                $this->queue->markFailed($event, $exception->getMessage(), true);
            }

            return;
        }

        $this->recordBatchExchange($domain, $events, $items);
        $this->applyD6Response($domain, $events, $response);
    }

    /**
     * Keep on each row, for the Log's Details (PRO-1965), its own item in
     * the wrapper's shape and the engine's reply as it concerns that row:
     * a D6 errors[] entry names its row by index, so no row carries another
     * row's error. Stored with the outcome markSent()/markFailed() records.
     *
     * @param IngestEvent[] $events indexed 0..n-1 in send order
     * @param array<int, array<string, mixed>> $items the same indexes
     */
    private function recordBatchExchange(string $domain, array $events, array $items): void
    {
        $exchange = $this->client->lastExchange();
        if ($exchange === null) {
            return;
        }

        $wrapper = Client::DOMAIN_WRAPPERS[$domain];
        foreach ($events as $index => $event) {
            $response = $exchange['response'];
            if (is_array($response) && is_array($response['body']['errors'] ?? null)) {
                $response['body']['errors'] = array_values(array_filter(
                    $response['body']['errors'],
                    static fn ($error): bool => is_array($error) && (int)($error['index'] ?? -1) === $index
                ));
            }
            $this->queue->recordExchange($event, [$wrapper => [$items[$index]]], $response);
        }
    }

    /**
     * Drain the §3b catalog/remove rows: one wrapper of unique product ids
     * per run. §3b has no per-item errors[] — a 2xx applies to every id.
     */
    private function flushCatalogRemove(): void
    {
        if ($this->settings->isRefused()) {
            return;
        }

        $events = $this->queue->claimBatch(Client::DOMAIN_CATALOG_REMOVE, Client::CATALOG_REMOVE_BATCH_LIMIT);
        if (!$events) {
            return;
        }

        $keyed = [];
        foreach ($events as $event) {
            $payload = $this->queue->decodePayload($event);
            $productId = trim((string)($payload['product_id'] ?? ''));
            if ($productId === '') {
                // No removal key — terminal, observable skip (never silent).
                $this->queue->markFailed($event, 'catalog/remove row has no product_id', true);
                continue;
            }
            $keyed[] = [$event, $productId];
        }
        if (!$keyed) {
            return;
        }

        // The engine is idempotent per id; still no reason to repeat one
        // inside a single wrapper (two queued rows for one product).
        $ids = array_values(array_unique(array_map(static fn (array $pair): string => $pair[1], $keyed)));

        try {
            $response = $this->client->catalogRemove($ids);
        } catch (EngineTransportException $exception) {
            $this->recordRemoveExchange($keyed);
            foreach ($keyed as [$event]) {
                $this->queue->markFailed($event, $exception->getMessage());
            }
            $this->logger->info('Catalog remove batch rescheduled', [
                'count' => count($keyed),
                'error' => $exception->getMessage(),
            ]);

            return;
        } catch (EngineRequestException $exception) {
            if ($this->releaseIfRefused(array_map(static fn (array $pair): IngestEvent => $pair[0], $keyed))) {
                return;
            }

            // 4xx is terminal: a malformed wrapper cannot improve by
            // resending, and a 404 means the engine predates §3b — the
            // periodic full re-sync stays the reconciler either way.
            $this->recordRemoveExchange($keyed);
            foreach ($keyed as [$event]) {
                $this->queue->markFailed($event, $exception->getMessage(), true);
            }

            return;
        }

        // On success the row's own outcome below replaces the batch reply.
        $this->recordRemoveExchange($keyed);
        $notFound = array_map('strval', (array)($response['not_found'] ?? []));
        foreach ($keyed as [$event, $productId]) {
            $this->queue->markSent($event, $this->serializer->serialize([
                'outcome' => in_array($productId, $notFound, true) ? 'not_found' : 'removed',
                'removed_products' => (int)($response['removed_products'] ?? 0),
                'rows_tombstoned' => (int)($response['rows_tombstoned'] ?? 0),
            ]));
        }
    }

    /**
     * The §3b counterpart of recordBatchExchange(): each row keeps its own
     * id in the wrapper's shape and the engine's reply.
     *
     * @param array<int, array{0: IngestEvent, 1: string}> $keyed
     */
    private function recordRemoveExchange(array $keyed): void
    {
        $exchange = $this->client->lastExchange();
        if ($exchange === null) {
            return;
        }

        foreach ($keyed as [$event, $productId]) {
            $this->queue->recordExchange($event, ['product_ids' => [$productId]], $exchange['response']);
        }
    }

    /**
     * A 4xx met while the account is refused is not the rows' fault
     * (contract §2): they go back to pending exactly as they were and wait
     * for the account to be active again. True when they were released.
     *
     * @param IngestEvent[] $events
     */
    private function releaseIfRefused(array $events): bool
    {
        if (!$this->settings->isRefused()) {
            return false;
        }

        $this->queue->release($events);

        return true;
    }

    /**
     * @param IngestEvent[] $events indexed 0..n-1 in send order
     * @param array<string, mixed> $response
     */
    private function applyD6Response(string $domain, array $events, array $response): void
    {
        $errorsByIndex = [];
        foreach ((array)($response['errors'] ?? []) as $error) {
            if (is_array($error) && isset($error['index'])) {
                $errorsByIndex[(int)$error['index']] = sprintf(
                    '%s: %s',
                    (string)($error['field'] ?? 'item'),
                    (string)($error['message'] ?? 'rejected by engine')
                );
            }
        }

        foreach ($events as $index => $event) {
            if (isset($errorsByIndex[$index])) {
                // Per-item validation error: terminal, the data won't improve
                // by resending the identical payload.
                $this->queue->markFailed($event, $errorsByIndex[$index], true);
            } else {
                $this->queue->markSent($event);
            }
        }

        if ($errorsByIndex) {
            $this->logger->info('Ingest batch had per-item errors', [
                'domain' => $domain,
                'errors' => count($errorsByIndex),
                'total' => count($events),
            ]);
        }

        // D6 invariant: processed + deduplicated + errors == total sent.
        $accounted = (int)($response['processed'] ?? 0)
            + (int)($response['deduplicated'] ?? 0)
            + count($errorsByIndex);
        if ($accounted !== count($events)) {
            $this->logger->info('D6 count invariant mismatch', [
                'domain' => $domain,
                'sent' => count($events),
                'accounted' => $accounted,
            ]);
        }
    }
}
