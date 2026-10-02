<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Privacy;

use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * The store's durable record of profiling opt-outs (PRO-3578, Woo
 * PRO-1194/PRO-3191/PRO-3192 parity): one flag row holding a map of
 * sha1(address) to the opt-out's moment. Only opt-outs are kept — the model
 * is opt-out, default-on, so an opt-in is the absence of an entry, and the
 * map stays as large as the number of people who said no, not the contact
 * base. The address itself is never stored. Addresses arrive normalised
 * (lower case, trimmed): ProfilingConsent is the one place that does it.
 *
 * The moment is the Unix time the store made the opt-out (My Account, an
 * unsubscribe, or the store writing the opt-out to the Smaily contact), or
 * 0 for an entry that only mirrors an opt-out read back from Smaily. A cache
 * flush or a Smaily outage cannot undo an entry; only an explicit opt-in
 * removes it.
 *
 * Every change is a read-modify-write of the one row, so it holds a named
 * lock: two shoppers opting out at the same moment must not lose one entry.
 */
class ProfilingOptOuts
{
    public const FLAG_CODE = 'smaily_connect_profiling_optouts';

    private const LOCK_NAME = 'smaily_connect_profiling_optouts';
    private const LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly LockManagerInterface $lockManager
    ) {
    }

    /**
     * The moment of the store's opt-out for this address, or null when the
     * store holds none.
     */
    public function moment(string $email): ?int
    {
        $moment = $this->all()[self::key($email)] ?? null;

        return is_int($moment) ? $moment : null;
    }

    public function record(string $email, int $moment): void
    {
        $this->change(static function (array $optOuts) use ($email, $moment): array {
            $optOuts[self::key($email)] = $moment;

            return $optOuts;
        });
    }

    public function forget(string $email): void
    {
        $this->change(static function (array $optOuts) use ($email): array {
            unset($optOuts[self::key($email)]);

            return $optOuts;
        });
    }

    /**
     * @param callable(array<string, int>): array<string, int> $change
     */
    private function change(callable $change): void
    {
        // A lock that cannot be had in time still lets the write through:
        // dropping the shopper's choice would be worse than the rare race.
        $this->lockManager->lock(self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS);
        try {
            $this->flagManager->saveFlag(self::FLAG_CODE, $change($this->all()));
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /**
     * @return array<string, int>
     */
    private function all(): array
    {
        $optOuts = $this->flagManager->getFlagData(self::FLAG_CODE);

        return is_array($optOuts) ? $optOuts : [];
    }

    private static function key(string $email): string
    {
        return sha1($email);
    }
}
