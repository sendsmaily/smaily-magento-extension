<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\AbandonedCart;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * State access for smaily_abandoned_cart (side table on the checkout
 * connection; the core quote table is never altered).
 *
 * Statuses: open (tracked), mailed (automation fired), completed (order
 * placed), expired (aged out unmailed), erased (Art. 17 tombstone — see
 * anonymizeForEmail()), skipped (not mailed: its address had a reminder in
 * the last 24 hours — see markSkipped()).
 */
class StateManager
{
    public const STATUS_OPEN = 'open';
    public const STATUS_MAILED = 'mailed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_ERASED = 'erased';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * Statuses nothing further happens to: the cron's gate and the retention
     * sweep both read them.
     */
    private const TERMINAL_STATUSES = [
        self::STATUS_MAILED,
        self::STATUS_COMPLETED,
        self::STATUS_EXPIRED,
        self::STATUS_ERASED,
        self::STATUS_SKIPPED,
    ];

    private const TABLE_NAME = 'smaily_abandoned_cart';
    private const CONNECTION = 'checkout';
    private const DELETE_CHUNK = 1000;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Record that an order was placed for a quote — the cart is no longer
     * abandoned and must never be mailed.
     *
     * An erased row keeps its status: a tombstone must not be resurrected.
     */
    public function markCompleted(int $quoteId): void
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $connection->insertOnDuplicate(
            $this->table(),
            [
                'quote_id' => $quoteId,
                'status' => self::STATUS_COMPLETED,
            ],
            [
                'status' => $connection->getCheckSql(
                    $connection->quoteInto('status = ?', self::STATUS_ERASED),
                    'status',
                    $connection->quote(self::STATUS_COMPLETED)
                ),
            ]
        );
    }

    /**
     * Record that the abandoned cart automation fired for a quote.
     */
    public function markMailed(int $quoteId, int $storeId, ?string $email): void
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $connection->insertOnDuplicate(
            $this->table(),
            [
                'quote_id' => $quoteId,
                'store_id' => $storeId,
                'email' => $email,
                'status' => self::STATUS_MAILED,
                'abandoned_at' => $this->dateTime->gmtDate(),
                'mail_sent_at' => $this->dateTime->gmtDate(),
            ],
            ['status', 'abandoned_at', 'mail_sent_at']
        );
    }

    /**
     * Record that a quote is not mailed because its address already had a
     * reminder for another cart in the last 24 hours (PRO-3693). Terminal,
     * so the cron does not weigh the quote again; no mail_sent_at, so the
     * skip does not count as a reminder.
     */
    public function markSkipped(int $quoteId, int $storeId, ?string $email): void
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $connection->insertOnDuplicate(
            $this->table(),
            [
                'quote_id' => $quoteId,
                'store_id' => $storeId,
                'email' => $email,
                'status' => self::STATUS_SKIPPED,
                'abandoned_at' => $this->dateTime->gmtDate(),
            ],
            ['status', 'abandoned_at']
        );
    }

    /**
     * Whether a reminder went to this address — delivered or still waiting in
     * the queue, for any cart — at or after $since (UTC, `Y-m-d H:i:s`). The
     * address is compared in any case.
     */
    public function hasReminderSince(string $email, string $since): bool
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $select = $connection->select()
            ->from($this->table(), ['quote_id'])
            ->where('LOWER(email) = ?', strtolower($email))
            ->where('mail_sent_at >= ?', $since)
            ->limit(1);

        return $connection->fetchOne($select) !== false;
    }

    /**
     * Store the checkout newsletter opt-in choice for a quote.
     *
     * The status is written on insert only: a tombstoned row may take the
     * fresh address the shopper types at a later checkout (their own new
     * input), but stays `erased`, so no reminder is ever scheduled for it
     * (PRO-2469).
     */
    public function setNewsletterOptin(int $quoteId, int $storeId, ?string $email, bool $optedIn): void
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $connection->insertOnDuplicate(
            $this->table(),
            [
                'quote_id' => $quoteId,
                'store_id' => $storeId,
                'email' => $email,
                'status' => self::STATUS_OPEN,
                'newsletter_optin' => $optedIn ? 1 : 0,
            ],
            ['email', 'newsletter_optin']
        );
    }

    /**
     * The tracked state of one quote: its status (STATUS_MAILED means the
     * reminder is either delivered or still waiting in the queue, PRO-2453)
     * and the checkout newsletter choice. Read BEFORE markCompleted(), which
     * overwrites the status. An untracked quote reads as the empty state.
     *
     * @return array{status: string, newsletter_optin: bool}
     */
    public function rowForQuote(int $quoteId): array
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $select = $connection->select()
            ->from($this->table(), ['status', 'newsletter_optin'])
            ->where('quote_id = ?', $quoteId);
        $row = $connection->fetchRow($select) ?: [];

        return [
            'status' => (string)($row['status'] ?? ''),
            'newsletter_optin' => (bool)($row['newsletter_optin'] ?? false),
        ];
    }

    /**
     * When the reminder for a quote was sent (UTC, `Y-m-d H:i:s`), or null
     * when none was.
     */
    public function mailSentAt(int $quoteId): ?string
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $select = $connection->select()
            ->from($this->table(), ['mail_sent_at'])
            ->where('quote_id = ?', $quoteId);
        $sentAt = $connection->fetchOne($select);

        return is_string($sentAt) && $sentAt !== '' ? $sentAt : null;
    }

    /**
     * Restrict a quote select to the quotes not in a terminal status — the
     * ones that may still be mailed (the cron's gate). $quoteIdColumn is the
     * select's quote id column, e.g. `main_table.entity_id`.
     *
     * A LEFT JOIN on the unique quote_id: at most one row per quote, so the
     * select's page still counts quotes. Done in SQL, not on a loaded page:
     * handled carts stay active and idle, and would otherwise fill the page
     * and keep newer carts out of it (PRO-3711).
     */
    public function excludeHandled(Select $select, string $quoteIdColumn): void
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $select->joinLeft(
            ['smaily_cart_state' => $this->table()],
            $connection->quoteInto(
                'smaily_cart_state.quote_id = ' . $quoteIdColumn . ' AND smaily_cart_state.status IN (?)',
                self::TERMINAL_STATUSES
            ),
            []
        );
        $select->where('smaily_cart_state.quote_id IS NULL');
    }

    /**
     * Turn every tracked cart of a contact into an email-less tombstone
     * (Art. 17 erasure). The address is expected already lowercased.
     *
     * The row is anonymised, never deleted (PRO-2467): the row IS the
     * "already handled" marker, and the module must not touch the core
     * `quote` table, so deleting it would let a still-active idle quote be
     * picked up again and mailed to the erased address. `erased` is a
     * terminal status like `completed` — excludeHandled() covers it,
     * so the cron neither mails the quote nor tracks it afresh.
     */
    public function anonymizeForEmail(string $email): int
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);

        return $connection->update(
            $this->table(),
            ['email' => null, 'status' => self::STATUS_ERASED],
            ['LOWER(email) = ?' => $email]
        );
    }

    /**
     * Mark every active cart that holds a contact's address — on the cart
     * itself or on one of its addresses — as an erased tombstone (Art. 17
     * erasure, PRO-3693). The address is expected already lowercased.
     *
     * anonymizeForEmail() reaches only the carts this table already tracks
     * under the address. A cart the scan has not picked up yet (idle less
     * than the cutoff, or a guest's typed email the scan has not seen) has no
     * row, or one without this address, and would be mailed to the erased
     * address on the next sweep. The core quote rows are only read: the
     * tombstone is what makes excludeHandled() skip them.
     *
     * @return int the carts newly marked
     */
    public function tombstoneActiveQuotesForEmail(string $email): int
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $quoteTable = $this->resourceConnection->getTableName('quote', self::CONNECTION);

        $byCart = $connection->select()
            ->from($quoteTable, ['entity_id', 'store_id'])
            ->where('is_active = ?', 1)
            ->where('LOWER(customer_email) = ?', $email);
        $byAddress = $connection->select()
            ->from(['quote_table' => $quoteTable], ['entity_id', 'store_id'])
            ->join(
                ['address' => $this->resourceConnection->getTableName('quote_address', self::CONNECTION)],
                'address.quote_id = quote_table.entity_id',
                []
            )
            ->where('quote_table.is_active = ?', 1)
            ->where('LOWER(address.email) = ?', $email);
        $either = $connection->select();
        $either->union([$byCart, $byAddress]);
        $storeByQuote = $connection->fetchPairs($either);
        if (!$storeByQuote) {
            return 0;
        }

        $erased = $connection->fetchCol(
            $connection->select()
                ->from($this->table(), ['quote_id'])
                ->where('quote_id IN (?)', array_map('intval', array_keys($storeByQuote)))
                ->where('status = ?', self::STATUS_ERASED)
        );
        $rows = [];
        foreach (array_diff_key($storeByQuote, array_flip(array_map('intval', $erased))) as $quoteId => $storeId) {
            $rows[] = [
                'quote_id' => (int)$quoteId,
                'store_id' => (int)$storeId,
                'email' => null,
                'status' => self::STATUS_ERASED,
            ];
        }
        if ($rows) {
            $connection->insertOnDuplicate($this->table(), $rows, ['email', 'status']);
        }

        return count($rows);
    }

    /**
     * The tracked carts of a contact, for the Art. 15 export. The address is
     * expected already lowercased.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rowsForEmail(string $email): array
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $select = $connection->select()
            ->from($this->table(), ['quote_id', 'store_id', 'status', 'created_at'])
            ->where('LOWER(email) = ?', $email)
            ->order('quote_id ASC');

        return $connection->fetchAll($select);
    }

    /**
     * Retention sweep, driven by Cron\QueueJanitor: drop terminal rows —
     * including the Art. 17 tombstone — last touched before the cutoff.
     */
    public function pruneTerminal(string $cutoff): int
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $select = $connection->select()
            ->from($this->table(), ['id'])
            ->where('status IN (?)', self::TERMINAL_STATUSES)
            ->where('updated_at < ?', $cutoff)
            ->limit(self::DELETE_CHUNK);

        return $this->deleteInChunks($select);
    }

    /**
     * Retention sweep: drop rows whose quote is gone from the store, whatever
     * their status — a marker with nothing left to mark. Magento's own quote
     * cleanup does not cascade onto this side table.
     */
    public function pruneOrphans(): int
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $select = $connection->select()
            ->from(['cart' => $this->table()], ['id'])
            ->joinLeft(
                ['quote_table' => $this->resourceConnection->getTableName('quote', self::CONNECTION)],
                'quote_table.entity_id = cart.quote_id',
                []
            )
            ->where('quote_table.entity_id IS NULL')
            ->limit(self::DELETE_CHUNK);

        return $this->deleteInChunks($select);
    }

    /**
     * Delete the rows an id-selecting query matches, one chunk at a time.
     */
    private function deleteInChunks(Select $select): int
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);

        $totalDeleted = 0;
        do {
            $ids = $connection->fetchCol($select);
            if ($ids) {
                $totalDeleted += $connection->delete($this->table(), ['id IN (?)' => $ids]);
            }
        } while (count($ids) === self::DELETE_CHUNK);

        return $totalDeleted;
    }

    private function table(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE_NAME, self::CONNECTION);
    }
}
