<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Logger\Logger;

/**
 * How much order history a history import sends, and in which order — the
 * engine's `config.history` (contract v1.20.0 §1, §2, §25, CON-241):
 * `start_months` (the start part), `horizon_months` (no older order is
 * sent) and `order` (`newest_first`, the one order the contract names).
 *
 * An import that starts asks the ping first, so a change on the engine's
 * side reaches the store without reconnecting; the block the setup
 * exchange stored is the answer when the ping has none or cannot be
 * reached, and 18 / 60 / newest_first when neither has it (logged). The
 * ping's block is not stored over the exchange's: the import keeps the one
 * it started with (Backfill\HistoryImport).
 */
class HistoryConfig
{
    public const FALLBACK = ['start_months' => 18, 'horizon_months' => 60, 'order' => 'newest_first'];

    public function __construct(
        private readonly Client $client,
        private readonly Settings $settings,
        private readonly Logger $logger
    ) {
    }

    /**
     * The window for a history import that starts now.
     *
     * @return array{start_months: int, horizon_months: int, order: string}
     */
    public function forNewImport(): array
    {
        try {
            $ping = $this->client->ping();
            $fromPing = $this->valid(is_array($ping['config'] ?? null) ? ($ping['config']['history'] ?? null) : null);
        } catch (EngineException) {
            $fromPing = null;
        }
        if ($fromPing !== null) {
            return $fromPing;
        }

        $stored = $this->fromExchange();
        if ($stored === null) {
            $this->logger->info('Campaign Intelligence sent no history window; the import uses the default', [
                'start_months' => self::FALLBACK['start_months'],
                'horizon_months' => self::FALLBACK['horizon_months'],
            ]);
        }

        return $stored ?? self::FALLBACK;
    }

    /**
     * The window the setup exchange stored, or the default — without asking
     * the engine.
     *
     * @return array{start_months: int, horizon_months: int, order: string}
     */
    public function stored(): array
    {
        return $this->fromExchange() ?? self::FALLBACK;
    }

    /**
     * @return array{start_months: int, horizon_months: int, order: string}|null
     */
    private function fromExchange(): ?array
    {
        return $this->valid($this->settings->getEngineConfig()['history'] ?? null);
    }

    /**
     * A block whose months are whole numbers, the start part inside the
     * horizon; null otherwise.
     *
     * @return array{start_months: int, horizon_months: int, order: string}|null
     */
    private function valid(mixed $history): ?array
    {
        if (!is_array($history)) {
            return null;
        }
        $start = filter_var($history['start_months'] ?? null, FILTER_VALIDATE_INT);
        $horizon = filter_var($history['horizon_months'] ?? null, FILTER_VALIDATE_INT);
        if ($start === false || $horizon === false || $start < 1 || $horizon < $start) {
            return null;
        }

        return [
            'start_months' => $start,
            'horizon_months' => $horizon,
            'order' => is_string($history['order'] ?? null) ? $history['order'] : self::FALLBACK['order'],
        ];
    }
}
