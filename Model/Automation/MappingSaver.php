<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Automation;

use Magento\Framework\DB\Select;
use Smaily\Connect\Model\Config\Source\MultilingualMode;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;

/**
 * Persists the per-language workflow mapping table (smaily_automation_mapping)
 * from the admin panels — the ONLY admin write path for the table (the 2.8.x
 * migration only seeds fallback rows).
 *
 * Semantics (Woo SettingsEndpoint::replace_automation_mappings parity, but
 * scope-aware): the caller sends the FULL desired state for the given website
 * scope; rows are upserted on the (website, trigger, language, account)
 * unique key and rows the payload no longer contains are deleted — saving the
 * same payload twice is a no-op. Only the known automation triggers within
 * the given website scope are managed; rows other websites own (seeded by a
 * website-scoped 2.8.x migration) are left alone. Given a multilingual mode,
 * only that mode's rows are managed (the rows the Router reads in it,
 * Mapping\Collection::addModeFilter(), CON-184): a save in mode A keeps the
 * website's mode-B rows, and the other way round (CON-188).
 *
 * A website with its own automations that reads the store-wide rows
 * (website 0) beside its own sends what its page shows (CON-396): a
 * workflow it clears where a store-wide row of the same trigger, language
 * and account exists is saved as its own row without a workflow
 * (workflow_id 0), which hides that store-wide row from it in the Router.
 */
class MappingSaver
{
    private const MANAGED_TRIGGERS = Trigger::ALL;

    public function __construct(
        private readonly MappingResource $resource
    ) {
    }

    /**
     * @param array<int, mixed> $rows desired mapping rows:
     *        {trigger_type, language, account_key?, workflow_id, is_default_fallback?}
     * @param int $websiteId the target website scope (the Settings/wizard panels
     *        pass the real target website; 0 is the legacy/global scope left
     *        behind by pre-Phase-3 saves and the 2.8.x migration's default-scope
     *        seeding — still readable, per the Router's website-or-0 fallback)
     * @param array<string, array<int, string>>|null $availableByAccount workflow
     *        ids each account key (language, or 'default') can currently list.
     *        When provided, a stale existing row (one the desired state no longer
     *        contains) whose workflow id is NOT in its account's list is
     *        preserved instead of deleted — a saved id missing from the freshly
     *        loaded list (deleted in Smaily, or an unloadable/absent list) was
     *        never offered in the select, so its absence is not a deliberate
     *        clear (PRO-1286). Null keeps the plain full-sync delete (callers
     *        that do not resolve the lists, e.g. migrations/tests).
     * @param string|null $mode the multilingual mode the rows are for: only
     *        the existing rows of that mode's accounts are replaced; null
     *        replaces the rows of every account
     * @param bool $hidesShared the website reads the store-wide rows beside
     *        its own: a cleared workflow of a store-wide row is saved as a
     *        row without a workflow (see the class comment)
     * @return array<int, array{field: string, message: string}> empty on success
     */
    public function save(
        array $rows,
        int $websiteId = 0,
        ?array $availableByAccount = null,
        ?string $mode = null,
        bool $hidesShared = false
    ): array {
        $desired = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $trigger = strtolower(trim((string)($row['trigger_type'] ?? '')));
            if (!in_array($trigger, self::MANAGED_TRIGGERS, true)) {
                return [[
                    'field' => 'mappings',
                    'message' => (string)__('Unknown automation trigger "%1".', $trigger),
                ]];
            }

            $language = strtolower(trim((string)($row['language'] ?? '')));
            $accountKey = strtolower(trim((string)($row['account_key'] ?? Mapping::ACCOUNT_DEFAULT)));
            if ($accountKey === '') {
                $accountKey = Mapping::ACCOUNT_DEFAULT;
            }
            if (!$this->isValidKey($language) || !$this->isValidKey($accountKey)) {
                return [[
                    'field' => 'mappings',
                    'message' => (string)__('Invalid language or account key in the workflow mapping.'),
                ]];
            }

            // A cleared select posts no row at all; a zero workflow id is
            // treated the same — the row is simply not part of the desired
            // state and gets deleted below.
            $workflowId = (int)($row['workflow_id'] ?? 0);
            if ($workflowId <= 0) {
                continue;
            }

            $desired[$trigger . '|' . $language . '|' . $accountKey] = [
                'website_id' => $websiteId,
                'trigger_type' => $trigger,
                'language' => $language,
                'account_key' => $accountKey,
                'workflow_id' => $workflowId,
                'is_default_fallback' => empty($row['is_default_fallback']) ? 0 : 1,
            ];
        }

        // At most one default-fallback row per trigger: the first flagged row
        // wins, later flags are dropped instead of failing the save.
        $fallbackSeen = [];
        foreach ($desired as &$row) {
            if ($row['is_default_fallback'] === 1) {
                if (isset($fallbackSeen[$row['trigger_type']])) {
                    $row['is_default_fallback'] = 0;
                } else {
                    $fallbackSeen[$row['trigger_type']] = true;
                }
            }
        }
        unset($row);

        $this->sync($desired, $websiteId, self::MANAGED_TRIGGERS, $availableByAccount, $mode, $hidesShared);

        return [];
    }

    /**
     * Persists a website's transactional-email workflows (PRO-4062): one row
     * per trigger and store language ('default' on a website with one
     * language), under the transactional account key, at most one of them
     * the trigger's default fallback. The caller sends the full desired
     * state for the website, as save() does; rows of the other triggers are
     * never touched.
     *
     * @param array<int, mixed> $rows desired rows:
     *        {trigger_type, language, workflow_id, is_default_fallback?}
     * @return array<int, array{field: string, message: string}> empty on success
     */
    public function saveTransactional(array $rows, int $websiteId): array
    {
        $desired = [];
        $fallbackSeen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $trigger = strtolower(trim((string)($row['trigger_type'] ?? '')));
            if (!in_array($trigger, Trigger::TRANSACTIONAL, true)) {
                return [[
                    'field' => 'mappings',
                    'message' => (string)__('Unknown automation trigger "%1".', $trigger),
                ]];
            }
            $language = strtolower(trim((string)($row['language'] ?? '')));
            if (!$this->isValidKey($language)) {
                return [[
                    'field' => 'mappings',
                    'message' => (string)__('Invalid language or account key in the workflow mapping.'),
                ]];
            }
            $workflowId = (int)($row['workflow_id'] ?? 0);
            if ($workflowId <= 0) {
                continue;
            }
            // At most one default-fallback row per trigger: the first wins.
            $isFallback = !empty($row['is_default_fallback']) && !isset($fallbackSeen[$trigger]);
            if ($isFallback) {
                $fallbackSeen[$trigger] = true;
            }
            $desired[$trigger . '|' . $language . '|' . Mapping::ACCOUNT_TRANSACTIONAL] = [
                'website_id' => $websiteId,
                'trigger_type' => $trigger,
                'language' => $language,
                'account_key' => Mapping::ACCOUNT_TRANSACTIONAL,
                'workflow_id' => $workflowId,
                'is_default_fallback' => $isFallback ? 1 : 0,
            ];
        }

        $this->sync($desired, $websiteId, Trigger::TRANSACTIONAL, null);

        return [];
    }

    /**
     * Deletes the website's rows of the given triggers that the desired state
     * no longer contains, then upserts the desired rows.
     *
     * @param array<string, array<string, int|string>> $desired keyed "trigger|language|account"
     * @param string[] $triggers
     * @param array<string, array<int, string>>|null $availableByAccount see save()
     * @param string|null $mode see save()
     * @param bool $hidesShared see save()
     */
    private function sync(
        array $desired,
        int $websiteId,
        array $triggers,
        ?array $availableByAccount,
        ?string $mode = null,
        bool $hidesShared = false
    ): void {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getMainTable();

        $existing = $connection->fetchAll($this->rowsSelect($websiteId, $triggers, $mode));
        $staleIds = [];
        $kept = [];
        foreach ($existing as $row) {
            $key = $row['trigger_type'] . '|' . $row['language'] . '|' . $row['account_key'];
            if (isset($desired[$key])) {
                continue;
            }
            if ($availableByAccount !== null && $this->isMissingFromList($row, $availableByAccount)) {
                // Saved id absent from its account's live list — keep the row.
                $kept[$key] = true;
                continue;
            }
            $staleIds[] = (int)$row['id'];
        }
        if ($hidesShared && $websiteId > 0) {
            foreach ($connection->fetchAll($this->rowsSelect(0, $triggers, $mode)) as $row) {
                $key = $row['trigger_type'] . '|' . $row['language'] . '|' . $row['account_key'];
                // A store-wide workflow missing from its account's list was
                // never offered in the select: its empty post is no clear.
                if ((int)$row['workflow_id'] <= 0 || isset($desired[$key]) || isset($kept[$key])
                    || ($availableByAccount !== null && $this->isMissingFromList($row, $availableByAccount))
                ) {
                    continue;
                }
                $desired[$key] = [
                    'website_id' => $websiteId,
                    'trigger_type' => $row['trigger_type'],
                    'language' => $row['language'],
                    'account_key' => $row['account_key'],
                    'workflow_id' => 0,
                    'is_default_fallback' => 0,
                ];
            }
        }
        if ($staleIds !== []) {
            $connection->delete($table, ['id IN (?)' => $staleIds]);
        }

        if ($desired !== []) {
            $connection->insertOnDuplicate(
                $table,
                array_values($desired),
                ['workflow_id', 'is_default_fallback']
            );
        }
    }

    /**
     * A website's rows of the given triggers; given a mode, only the rows of
     * its accounts, as Mapping\Collection::addModeFilter() reads them.
     *
     * @param string[] $triggers
     */
    private function rowsSelect(int $websiteId, array $triggers, ?string $mode): Select
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getMainTable(), ['id', 'trigger_type', 'language', 'account_key', 'workflow_id'])
            ->where('website_id = ?', $websiteId)
            ->where('trigger_type IN (?)', $triggers);
        if ($mode === MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS) {
            $select->where(
                $connection->quoteInto('account_key <> ?', Mapping::ACCOUNT_DEFAULT)
                . ' OR ' . $connection->quoteInto('language = ?', Mapping::LANGUAGE_DEFAULT)
            );
        } elseif ($mode !== null) {
            $select->where('account_key = ?', Mapping::ACCOUNT_DEFAULT);
        }

        return $select;
    }

    private function isValidKey(string $value): bool
    {
        return $value === Mapping::LANGUAGE_DEFAULT || preg_match('/^[a-z]{2,3}$/', $value) === 1;
    }

    /**
     * A stale existing row is preserved when its workflow id cannot be
     * confirmed present in its account's freshly loaded list: an account key
     * we did not resolve, or a resolved-but-empty list (load failure), both
     * count as "unknown" and keep the row; only an id that IS in the account's
     * list is a confirmed deliberate clear and gets deleted (PRO-1286).
     *
     * @param array<string, mixed> $row existing row {account_key, workflow_id, ...}
     * @param array<string, array<int, string>> $availableByAccount
     */
    private function isMissingFromList(array $row, array $availableByAccount): bool
    {
        $accountKey = (string)($row['account_key'] ?? Mapping::ACCOUNT_DEFAULT);
        if (!array_key_exists($accountKey, $availableByAccount)) {
            return true;
        }

        return !in_array((string)($row['workflow_id'] ?? ''), $availableByAccount[$accountKey], true);
    }
}
