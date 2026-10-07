<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Automation;

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
 * website-scoped 2.8.x migration) are left alone.
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
     * @return array<int, array{field: string, message: string}> empty on success
     */
    public function save(array $rows, int $websiteId = 0, ?array $availableByAccount = null): array
    {
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

        $connection = $this->resource->getConnection();
        $table = $this->resource->getMainTable();

        $existing = $connection->fetchAll(
            $connection->select()
                ->from($table, ['id', 'trigger_type', 'language', 'account_key', 'workflow_id'])
                ->where('website_id = ?', $websiteId)
                ->where('trigger_type IN (?)', self::MANAGED_TRIGGERS)
        );
        $staleIds = [];
        foreach ($existing as $row) {
            $key = $row['trigger_type'] . '|' . $row['language'] . '|' . $row['account_key'];
            if (isset($desired[$key])) {
                continue;
            }
            if ($availableByAccount !== null && $this->isMissingFromList($row, $availableByAccount)) {
                // Saved id absent from its account's live list — keep the row.
                continue;
            }
            $staleIds[] = (int)$row['id'];
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

        return [];
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
