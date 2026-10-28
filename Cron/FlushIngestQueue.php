<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Cron;

use Magento\Framework\Serialize\Serializer\Json;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\IngestPace;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\OptOutReplay;

/**
 * Drains the engine ingest queue: one batch per domain in turn, round after
 * round, until the queue is empty, the run's budget is spent or the engine
 * asks it to slow down (Engine\IngestPace, CON-232). A store's history
 * import is not held to one batch per domain a minute, and a row the store
 * queues meanwhile waits behind at most the import's backlog, never the
 * whole history.
 * The run ends early, leaving the rest to the next run, on a 429 (also one a
 * retry got past) or a batch that failed in transport; a whole-batch 4xx
 * ends its own domain for the run, so a key the engine no longer takes
 * fails one batch per domain a run, not the whole queue.
 *
 * Each run first builds the catalog rows of the products the stock hooks
 * only marked changed (CatalogIngest::buildChanged(), PRO-1967), so they go
 * out with this run's catalog batch.
 *
 * D6 responses are per-item: errors[].index maps back onto the batch rows,
 * so a 200 never marks the whole batch sent blindly (contract scar #5).
 * Transport failures (429 after retries, 5xx, network) reschedule the batch
 * with backoff; per-item validation errors are terminal for that row.
 *
 * The items the engine confirmed are handed to the privacy layer
 * (Privacy\OptOutReplay, PRO-3760).
 *
 * The catalog_remove domain (§3b product-level removal, PRO-1231) is NOT
 * D6 and gets its own flush path: on a 2xx every batched id was applied —
 * an id in `not_found` is a contract-defined success ("already removed, or
 * never sent"), recorded as the row's outcome, never retried.
 */
class FlushIngestQueue
{
    /** Set when this run must send nothing more. */
    private bool $stopped = false;

    /** @var array<string, true> domains that send nothing more this run */
    private array $haltedDomains = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly IngestQueue $queue,
        private readonly CatalogIngest $catalogIngest,
        private readonly Client $client,
        private readonly OptOutReplay $optOutReplay,
        private readonly Json $serializer,
        private readonly Logger $logger,
        private readonly IngestPace $pace
    ) {
    }

    public function execute(): void
    {
        if (!$this->settings->isSendingAllowed()) {
            return;
        }

        $this->queue->requeueStale();
        try {
            $this->catalogIngest->buildChanged();
        } catch (\Throwable $exception) {
            // The other domains still go out; the claimed markers come back
            // through requeueStale().
            $this->logger->error('Catalog build of changed products failed', [
                'error' => $exception->getMessage(),
            ]);
        }

        $this->stopped = false;
        $this->haltedDomains = [];
        $this->pace->start();
        do {
            $sent = false;
            foreach (array_keys(Client::DOMAIN_WRAPPERS) as $domain) {
                $sent = $this->flushDomain($domain) || $sent;
            }
            $sent = $this->flushCatalogRemove() || $sent;
        } while ($sent);
    }

    /**
     * Whether this run may claim another batch of the domain. Re-asked per
     * batch: a refusal met by one batch stops the rest of this run instead
     * of collecting more 403s. Only the refusal can appear mid-run —
     * execute() proved connectedness.
     */
    private function maySend(string $domain): bool
    {
        if ($this->stopped || isset($this->haltedDomains[$domain]) || $this->settings->isRefused()) {
            return false;
        }
        if (!$this->pace->hasTime()) {
            $this->stopped = true;

            return false;
        }

        return true;
    }

    /**
     * End the run when the engine asked to slow down, even though a retry
     * got this batch through.
     */
    private function stopIfThrottled(): void
    {
        if ($this->client->wasThrottled()) {
            $this->stopped = true;
        }
    }

    /**
     * Send one batch of the domain. True when a batch was claimed.
     */
    private function flushDomain(string $domain): bool
    {
        if (!$this->maySend($domain)) {
            return false;
        }

        $events = $this->queue->claimBatch($domain, Client::DOMAIN_BATCH_LIMITS[$domain]);
        if (!$events) {
            return false;
        }

        $items = [];
        foreach ($events as $index => $event) {
            $items[$index] = $this->queue->decodePayload($event);
        }

        $this->pace->awaitTurn();
        try {
            $response = $this->client->ingest($domain, $items);
        } catch (EngineTransportException $exception) {
            $this->stopped = true;
            $this->recordBatchExchange($domain, $events, $items);
            $this->queue->markFailedMany($events, $exception->getMessage());
            $this->logger->info('Ingest batch rescheduled', [
                'domain' => $domain,
                'count' => count($events),
                'error' => $exception->getMessage(),
            ]);

            return true;
        } catch (EngineRequestException $exception) {
            $this->haltedDomains[$domain] = true;
            if ($this->releaseIfRefused($events)) {
                return true;
            }

            // Whole-batch 4xx: the request shape is wrong; retrying the same
            // rows cannot succeed.
            $this->recordBatchExchange($domain, $events, $items);
            $this->queue->markFailedMany($events, $exception->getMessage(), true);

            return true;
        }

        $this->stopIfThrottled();
        $this->recordBatchExchange($domain, $events, $items);
        $this->applyD6Response($domain, $events, $items, $response);

        return true;
    }

    /**
     * Keep on each row, for the Log's Details (PRO-1965), its own item in
     * the wrapper's shape and the engine's reply as it concerns that row:
     * a D6 errors[] entry names its row by index, so no row carries another
     * row's error. Stored with the outcome markSent()/markFailedMany() records.
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
     * Send one §3b catalog/remove batch: one wrapper of unique product ids.
     * §3b has no per-item errors[] — a 2xx applies to every id. True when a
     * batch was claimed.
     */
    private function flushCatalogRemove(): bool
    {
        if (!$this->maySend(Client::DOMAIN_CATALOG_REMOVE)) {
            return false;
        }

        $events = $this->queue->claimBatch(Client::DOMAIN_CATALOG_REMOVE, Client::CATALOG_REMOVE_BATCH_LIMIT);
        if (!$events) {
            return false;
        }

        $keyed = [];
        $keyless = [];
        foreach ($events as $event) {
            $payload = $this->queue->decodePayload($event);
            $productId = trim((string)($payload['product_id'] ?? ''));
            if ($productId === '') {
                $keyless[] = $event;
                continue;
            }
            $keyed[] = [$event, $productId];
        }
        if ($keyless) {
            // No removal key — terminal, observable skip (never silent).
            $this->queue->markFailedMany($keyless, 'catalog/remove row has no product_id', true);
        }
        if (!$keyed) {
            return true;
        }

        // The engine is idempotent per id; still no reason to repeat one
        // inside a single wrapper (two queued rows for one product).
        $ids = array_values(array_unique(array_map(static fn (array $pair): string => $pair[1], $keyed)));

        $this->pace->awaitTurn();
        try {
            $response = $this->client->catalogRemove($ids);
        } catch (EngineTransportException $exception) {
            $this->stopped = true;
            $this->recordRemoveExchange($keyed);
            $this->queue->markFailedMany($this->keyedEvents($keyed), $exception->getMessage());
            $this->logger->info('Catalog remove batch rescheduled', [
                'count' => count($keyed),
                'error' => $exception->getMessage(),
            ]);

            return true;
        } catch (EngineRequestException $exception) {
            $this->haltedDomains[Client::DOMAIN_CATALOG_REMOVE] = true;
            if ($this->releaseIfRefused($this->keyedEvents($keyed))) {
                return true;
            }

            // 4xx is terminal: a malformed wrapper cannot improve by
            // resending, and a 404 means the engine predates §3b — the
            // parked row can be retried from the Log once it has it.
            $this->recordRemoveExchange($keyed);
            $this->queue->markFailedMany($this->keyedEvents($keyed), $exception->getMessage(), true);

            return true;
        }

        // On success the row's own outcome below replaces the batch reply.
        $this->stopIfThrottled();
        $this->recordRemoveExchange($keyed);
        $notFound = array_map('strval', (array)($response['not_found'] ?? []));
        foreach ($keyed as [$event, $productId]) {
            $this->queue->markSent($event, $this->serializer->serialize([
                'outcome' => in_array($productId, $notFound, true) ? 'not_found' : 'removed',
                'removed_products' => (int)($response['removed_products'] ?? 0),
                'rows_tombstoned' => (int)($response['rows_tombstoned'] ?? 0),
            ]));
        }

        return true;
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
     * @param array<int, array{0: IngestEvent, 1: string}> $keyed
     * @return IngestEvent[]
     */
    private function keyedEvents(array $keyed): array
    {
        return array_map(static fn (array $pair): IngestEvent => $pair[0], $keyed);
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
     * @param array<int, array<string, mixed>> $items the same indexes
     * @param array<string, mixed> $response
     */
    private function applyD6Response(string $domain, array $events, array $items, array $response): void
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

        $confirmedItems = [];
        $failed = [];
        foreach ($events as $index => $event) {
            if (isset($errorsByIndex[$index])) {
                $failed[$errorsByIndex[$index]][] = $event;
            } else {
                $this->queue->markSent($event);
                $confirmedItems[] = $items[$index];
            }
        }
        // Per-item validation error: terminal, the data won't improve by
        // resending the identical payload. One write per reason (PRO-3962).
        foreach ($failed as $reason => $group) {
            $this->queue->markFailedMany($group, (string)$reason, true);
        }
        $this->optOutReplay->afterConfirmed($domain, $confirmedItems);

        if ($errorsByIndex) {
            $this->logger->info('Ingest batch had per-item errors', [
                'domain' => $domain,
                'errors' => count($errorsByIndex),
                'total' => count($events),
            ]);
        }

        // D6 invariant: processed + deduplicated + skipped + errors == total
        // sent. `skipped` (contract §5, v1.20.0) counts orders older than the
        // history horizon that the engine did not store; they are not errors
        // and are not retried, so their rows stay marked sent.
        $accounted = (int)($response['processed'] ?? 0)
            + (int)($response['deduplicated'] ?? 0)
            + (int)($response['skipped'] ?? 0)
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
