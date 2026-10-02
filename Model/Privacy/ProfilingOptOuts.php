<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Privacy;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * The store's durable record of profiling opt-outs (PRO-3578, Woo
 * PRO-1194/PRO-3191/PRO-3192 parity): one flag row holding a map of a keyed
 * hash of the address (HMAC-SHA256 with the installation crypt key) to the
 * opt-out's moment. Only opt-outs are kept — the model
 * is opt-out, default-on, so an opt-in is the absence of an entry, and the
 * map stays as large as the number of people who said no, not the contact
 * base. The address itself is never stored. Addresses arrive normalised
 * (lower case, trimmed): ProfilingConsent is the one place that does it.
 *
 * The moment is the Unix time the store made the opt-out (My Account, an
 * unsubscribe, or the store writing the opt-out to the Smaily contact), or
 * 0 for an entry that only mirrors an opt-out read back from Smaily. A cache
 * flush or a Smaily outage cannot undo an entry; only an explicit opt-in
 * removes it — or, for an opt-out the shopper made only by unsubscribing
 * from marketing, subscribing again (PRO-3594). Such an entry is kept as
 * {"at": moment, "by": "unsubscribe"}; a plain moment, the only form before
 * PRO-3594, is a profiling opt-out of its own.
 *
 * An entry is also found under the plain sha1(address) the record used
 * before (PRO-3575) and under an earlier crypt key after a key rotation; any
 * change to the address's entry rewrites it under the newest key, so no
 * opt-out is lost.
 *
 * Every change is a read-modify-write of the one row, so it holds a named
 * lock: two shoppers opting out at the same moment must not lose one entry.
 */
class ProfilingOptOuts
{
    public const FLAG_CODE = 'smaily_connect_profiling_optouts';

    private const LOCK_NAME = 'smaily_connect_profiling_optouts';
    private const LOCK_TIMEOUT_SECONDS = 10;
    private const BY_UNSUBSCRIBE = 'unsubscribe';

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly LockManagerInterface $lockManager,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * The moment of the store's opt-out for this address, or null when the
     * store holds none.
     */
    public function moment(string $email): ?int
    {
        $entry = $this->entry($email);
        $moment = is_array($entry) ? ($entry['at'] ?? null) : $entry;

        return is_int($moment) ? $moment : null;
    }

    /**
     * Did the store's opt-out come only from unsubscribing from marketing?
     */
    public function isByUnsubscribe(string $email): bool
    {
        $entry = $this->entry($email);

        return is_array($entry) && ($entry['by'] ?? null) === self::BY_UNSUBSCRIBE;
    }

    public function record(string $email, int $moment, bool $byUnsubscribe = false): void
    {
        $entry = $byUnsubscribe ? ['at' => $moment, 'by' => self::BY_UNSUBSCRIBE] : $moment;
        $keys = $this->keys($email);
        $this->change(static function (array $optOuts) use ($keys, $entry): array {
            $optOuts = array_diff_key($optOuts, array_flip($keys));
            $optOuts[$keys[0]] = $entry;

            return $optOuts;
        });
    }

    public function forget(string $email): void
    {
        $keys = $this->keys($email);
        $this->change(static function (array $optOuts) use ($keys): array {
            return array_diff_key($optOuts, array_flip($keys));
        });
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $change
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
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $optOuts = $this->flagManager->getFlagData(self::FLAG_CODE);

        return is_array($optOuts) ? $optOuts : [];
    }

    /**
     * The address's entry under any of its keys, or null.
     */
    private function entry(string $email): mixed
    {
        $optOuts = $this->all();
        foreach ($this->keys($email) as $key) {
            if (array_key_exists($key, $optOuts)) {
                return $optOuts[$key];
            }
        }

        return null;
    }

    /**
     * The keys an address's entry may be stored under: the current form
     * first (HMAC with the newest crypt key), then the earlier crypt keys,
     * then the plain sha1 of the record's first form.
     *
     * @return string[]
     */
    private function keys(string $email): array
    {
        $raw = (string)$this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY);
        // Multiple keys are newline-separated after a key rotation, newest last.
        $cryptKeys = array_reverse(array_values(array_filter(array_map('trim', explode("\n", $raw)))));

        $keys = [];
        foreach ($cryptKeys as $cryptKey) {
            $keys[] = hash_hmac('sha256', 'smaily-profiling-optout|' . $email, $cryptKey);
        }
        $keys[] = sha1($email);

        return $keys;
    }
}
