<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Automation;

/**
 * Normalizes one posted engine-automation row (contract §13) into the shape
 * putAutomationsConfig expects. Pure and side-effect free so the binding-
 * preservation rules can be unit tested in isolation.
 *
 * Binding preservation — a save must NEVER silently wipe a workflow binding:
 *  - per_language: a map saved from another platform on the same tenant (it
 *    carries per-language ids the single select cannot represent) is kept as
 *    long as the merchant did not change the fallback workflow here — OR the
 *    saved fallback id is itself missing from the list, so the empty post is
 *    not a deliberate clear (PRO-1286).
 *  - single: a saved workflow id that is ABSENT from the freshly loaded Smaily
 *    workflow list (deleted in Smaily, or the list failed to load) was never
 *    offered in the dropdown, so an empty post is not a deliberate clear — the
 *    binding is kept rather than dropped (PRO-1268). An id that IS in the list
 *    but was cleared to "-- Not Selected --" is a real clear and is honored.
 *
 * The "keep a saved id when the posted value is empty and the id is not in the
 * current list" decision lives in one place — {@see self::isMissingFromList()} —
 * and is reused by the other admin save paths (wizard/Settings single-mode
 * selects, the per-language mapping editor) so the resilience is identical
 * everywhere (PRO-1286).
 */
class ConfigRowNormalizer
{
    /**
     * @param array<string, mixed> $data posted trigger fields
     * @param array<int, string> $availableWorkflowIds ids in the freshly
     *        loaded Smaily workflow list (string form); an empty list means the
     *        list could not be loaded — every saved id then counts as missing.
     * @return array<string, mixed>
     */
    public function normalize(string $triggerKey, array $data, array $availableWorkflowIds): array
    {
        $workflowId = trim((string)($data['workflow_id'] ?? ''));
        $dailyCap = trim((string)($data['daily_cap'] ?? ''));
        $testEmails = array_values(array_filter(array_map(
            'trim',
            explode(',', (string)($data['test_emails'] ?? ''))
        )));

        $originalMode = (string)($data['language_mode'] ?? 'single');
        $decoded = json_decode((string)($data['original_map'] ?? ''), true);
        $originalMap = is_array($decoded) ? $decoded : [];
        $savedSingleId = isset($originalMap['id']) ? (string)$originalMap['id'] : '';
        $savedFallbackId = (string)($originalMap['fallback'] ?? '');

        if ($originalMode === 'per_language'
            && ($workflowId === $savedFallbackId
                || $this->isMissingFromList($workflowId, $savedFallbackId, $availableWorkflowIds))
        ) {
            // Fallback unchanged, or the saved fallback id is missing from the
            // list (never offered, so the empty post is not a clear) — keep the
            // whole per-language map. See the class docblock.
            $languageMode = 'per_language';
            $map = $originalMap;
        } elseif ($this->isMissingFromList($workflowId, $savedSingleId, $availableWorkflowIds)) {
            // Missing-from-list preserve — see the class docblock.
            $languageMode = 'single';
            $map = ['id' => $savedSingleId];
        } else {
            $languageMode = 'single';
            $map = $workflowId !== '' ? ['id' => $workflowId] : [];
        }

        return [
            'trigger_key' => $triggerKey,
            'enabled' => !empty($data['enabled']) && $map !== [],
            'language_mode' => $languageMode,
            // An empty map must serialize as a JSON object, not [].
            'automation_map' => $map === [] ? new \stdClass() : $map,
            'cooldown_days' => max(1, min(365, (int)($data['cooldown_days'] ?? 7))),
            'daily_cap' => $dailyCap === '' ? null : max(1, min(100000, (int)$dailyCap)),
            'test_mode' => !empty($data['test_mode']),
            'test_emails' => array_slice($testEmails, 0, 50),
        ];
    }

    /**
     * The shared "keep a saved binding rather than silently drop it" decision,
     * reused across every admin workflow-save surface (PRO-1268/PRO-1286): a
     * saved workflow id is preserved when the posted value is empty AND that id
     * is not present in the freshly loaded Smaily workflow list. An empty list
     * (credentials missing or the listing failed) means "unknown", so every
     * saved id then counts as missing and is kept. An id that IS in the list
     * but was cleared is a deliberate clear and is NOT preserved.
     *
     * @param array<int, string> $availableWorkflowIds ids (string form) the
     *        Smaily API can currently list; empty = list unavailable.
     */
    public function isMissingFromList(string $postedId, string $savedId, array $availableWorkflowIds): bool
    {
        return $postedId === ''
            && $savedId !== ''
            && !in_array($savedId, $availableWorkflowIds, true);
    }
}
