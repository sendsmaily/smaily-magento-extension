<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Automation;

use Smaily\Connect\Model\Automation\MappingSaver;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * The admin mapping saver against a real smaily_automation_mapping table:
 * full-desired-state sync semantics (idempotent upsert on the unique key,
 * deletion of cleared rows, scope discipline) plus the Router reading the
 * result back through real SQL (website-specific rows beating global rows).
 */
class MappingSaverTest extends IntegrationTestCase
{
    private MappingSaver $saver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saver = $this->objectManager->create(MappingSaver::class);
    }

    public function testSavesUpsertsAndIsIdempotent(): void
    {
        $payload = [
            $this->row('welcome', 'et', 'et', 101, true),
            $this->row('welcome', 'en', 'en', 102),
            $this->row('abandoned_cart', 'et', 'default', 201),
        ];

        self::assertSame([], $this->saver->save($payload));
        $first = $this->fetchAll(MappingResource::TABLE_NAME);
        self::assertCount(3, $first);

        // Saving the exact same desired state again must not duplicate or
        // recreate rows (same primary keys survive).
        self::assertSame([], $this->saver->save($payload));
        $second = $this->fetchAll(MappingResource::TABLE_NAME);
        self::assertSame(
            array_column($first, 'id'),
            array_column($second, 'id'),
            'An unchanged payload must leave the same rows in place'
        );

        $byKey = $this->indexRows($second);
        self::assertSame('101', $byKey['welcome|et|et']['workflow_id']);
        self::assertSame('1', $byKey['welcome|et|et']['is_default_fallback']);
        self::assertSame('102', $byKey['welcome|en|en']['workflow_id']);
        self::assertSame('0', $byKey['welcome|en|en']['is_default_fallback']);
        self::assertSame('201', $byKey['abandoned_cart|et|default']['workflow_id']);
    }

    public function testResaveUpdatesWorkflowAndMovesTheFallbackFlag(): void
    {
        $this->saver->save([
            $this->row('welcome', 'et', 'et', 101, true),
            $this->row('welcome', 'en', 'en', 102),
        ]);

        $this->saver->save([
            $this->row('welcome', 'et', 'et', 111),
            $this->row('welcome', 'en', 'en', 102, true),
        ]);

        $byKey = $this->indexRows($this->fetchAll(MappingResource::TABLE_NAME));
        self::assertSame('111', $byKey['welcome|et|et']['workflow_id']);
        self::assertSame('0', $byKey['welcome|et|et']['is_default_fallback']);
        self::assertSame('1', $byKey['welcome|en|en']['is_default_fallback']);
    }

    public function testClearedRowsAreDeleted(): void
    {
        $this->saver->save([
            $this->row('welcome', 'et', 'et', 101),
            $this->row('welcome', 'en', 'en', 102),
            $this->row('first_order', 'et', 'et', 301),
        ]);

        // The merchant cleared the EN welcome select and every first_order
        // row; a zero workflow id counts as cleared too.
        $this->saver->save([
            $this->row('welcome', 'et', 'et', 101),
            $this->row('welcome', 'en', 'en', 0),
        ]);

        $rows = $this->fetchAll(MappingResource::TABLE_NAME);
        self::assertCount(1, $rows);
        self::assertSame('welcome', $rows[0]['trigger_type']);
        self::assertSame('et', $rows[0]['language']);
    }

    /**
     * PRO-1286: a full-desired-state sync must NOT drop a saved mapping row
     * whose workflow id is missing from its account's freshly loaded list — the
     * select never offered it, so its absence from the payload is not a clear.
     * A row whose id IS still in the list and was cleared is deleted as before.
     */
    public function testStaleRowWithMissingWorkflowIdIsPreserved(): void
    {
        $this->saver->save([
            $this->row('welcome', 'et', 'et', 101),
            $this->row('welcome', 'en', 'en', 102),
        ]);

        // The merchant cleared BOTH selects (empty desired state). ET's saved
        // workflow 101 is gone from ET's account list (kept), EN's 102 is still
        // present (deliberate clear → deleted).
        $this->saver->save([], 0, [
            'et' => ['999'],
            'en' => ['102'],
        ]);

        $byKey = $this->indexRows($this->fetchAll(MappingResource::TABLE_NAME));
        self::assertArrayHasKey('welcome|et|et', $byKey, 'Missing-from-list id is preserved');
        self::assertSame('101', $byKey['welcome|et|et']['workflow_id']);
        self::assertArrayNotHasKey('welcome|en|en', $byKey, 'Present id cleared is an honest delete');
    }

    /**
     * PRO-1286: the workflow list could not be loaded for an account (empty
     * list), or the account key was not resolved at all — either way the id is
     * unconfirmed, so the row is kept rather than blind-deleted.
     */
    public function testStaleRowIsPreservedWhenAccountListIsUnavailable(): void
    {
        $this->saver->save([
            $this->row('welcome', 'et', 'et', 101),
            $this->row('first_order', 'de', 'de', 301),
        ]);

        // ET list failed to load (empty); DE was not resolved at all (absent).
        $this->saver->save([], 0, ['et' => []]);

        $byKey = $this->indexRows($this->fetchAll(MappingResource::TABLE_NAME));
        self::assertArrayHasKey('welcome|et|et', $byKey, 'Empty (failed) list = unknown = kept');
        self::assertArrayHasKey('first_order|de|de', $byKey, 'Unresolved account = unknown = kept');
    }

    /**
     * The preserve logic is opt-in: callers that pass no account lists (the
     * 2.8.x migration, plain resaves) keep the plain full-sync delete.
     */
    public function testWithoutAccountListsFullSyncStillDeletesClearedRows(): void
    {
        $this->saver->save([$this->row('welcome', 'et', 'et', 101)]);
        $this->saver->save([]);

        self::assertCount(0, $this->fetchAll(MappingResource::TABLE_NAME));
    }

    public function testOnlyOneFallbackRowPerTriggerSurvives(): void
    {
        $this->saver->save([
            $this->row('welcome', 'et', 'et', 101, true),
            $this->row('welcome', 'en', 'en', 102, true),
        ]);

        $flags = array_column($this->fetchAll(MappingResource::TABLE_NAME), 'is_default_fallback');
        self::assertSame(['1', '0'], $flags, 'The first flagged row wins, later flags are dropped');
    }

    public function testOtherWebsitesRowsAreLeftAlone(): void
    {
        // A website-scoped row as the 2.8.x migration seeds it.
        $this->connection->insert(MappingResource::TABLE_NAME, [
            'website_id' => 5,
            'trigger_type' => 'welcome',
            'language' => 'default',
            'account_key' => 'default',
            'workflow_id' => 900,
            'is_default_fallback' => 1,
        ]);

        $this->saver->save([$this->row('welcome', 'et', 'et', 101)]);
        $this->saver->save([$this->row('first_order', 'et', 'et', 301)]);

        $byKey = $this->indexRows($this->fetchAll(MappingResource::TABLE_NAME));
        self::assertArrayHasKey('welcome|default|default', $byKey, 'Website 5 keeps its migrated row');
        self::assertSame('5', $byKey['welcome|default|default']['website_id']);
        self::assertArrayHasKey('first_order|et|et', $byKey);
        self::assertArrayNotHasKey('welcome|et|et', $byKey, 'Global rows still sync (deleted when cleared)');
    }

    public function testInvalidPayloadIsRejectedWithoutTouchingTheTable(): void
    {
        $this->saver->save([$this->row('welcome', 'et', 'et', 101)]);

        $errors = $this->saver->save([$this->row('surprise_trigger', 'et', 'et', 1)]);
        self::assertNotSame([], $errors);
        self::assertCount(1, $this->fetchAll(MappingResource::TABLE_NAME));

        $errors = $this->saver->save([$this->row('welcome', 'not a language!', 'et', 1)]);
        self::assertNotSame([], $errors);
        self::assertCount(1, $this->fetchAll(MappingResource::TABLE_NAME));
    }

    public function testRouterReadsSavedRowsAndPrefersWebsiteSpecificOnes(): void
    {
        $scopeConfig = $this->env->getScopeConfig();
        $scopeConfig->setValue(Config::XML_PATH_MULTILINGUAL_MODE, 'a');
        $scopeConfig->setValue(Config::XML_PATH_WELCOME_WORKFLOW, '9');

        $this->saver->save([
            $this->row('welcome', 'et', 'et', 101),
            $this->row('welcome', 'en', 'en', 102, true),
        ]);
        // A website-1 override for et (e.g. seeded by a website-scoped migration).
        $this->connection->insert(MappingResource::TABLE_NAME, [
            'website_id' => 1,
            'trigger_type' => 'welcome',
            'language' => 'et',
            'account_key' => 'default',
            'workflow_id' => 555,
            'is_default_fallback' => 0,
        ]);

        /** @var Router $router */
        $router = $this->objectManager->create(Router::class);

        $override = $router->resolve('welcome', 1, 'et');
        self::assertNotNull($override);
        self::assertSame(555, $override->workflowId, 'The website-specific row beats the global row');

        $global = $router->resolve('welcome', 2, 'et');
        self::assertNotNull($global);
        self::assertSame(101, $global->workflowId);
        self::assertSame('et', $global->accountKey);

        $fallback = $router->resolve('welcome', 2, 'ru');
        self::assertNotNull($fallback);
        self::assertSame(102, $fallback->workflowId, 'Unmapped language uses the default-fallback row');
        self::assertSame('en', $fallback->accountKey, 'The fallback row names its own account');

        $configDefault = $router->resolve('first_order', 2, 'ru');
        self::assertNull($configDefault, 'No rows and no config default is a terminal skip');

        $scopeConfig->setValue(Config::XML_PATH_FIRST_ORDER_WORKFLOW, '7');
        $viaConfig = $router->resolve('first_order', 2, 'ru');
        self::assertNotNull($viaConfig);
        self::assertSame(7, $viaConfig->workflowId);
        self::assertNull($viaConfig->accountKey, 'Config defaults carry no account key');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $trigger,
        string $language,
        string $accountKey,
        int $workflowId,
        bool $fallback = false
    ): array {
        return [
            'trigger_type' => $trigger,
            'language' => $language,
            'account_key' => $accountKey,
            'workflow_id' => $workflowId,
            'is_default_fallback' => $fallback,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexRows(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['trigger_type'] . '|' . $row['language'] . '|' . $row['account_key']] = $row;
        }

        return $indexed;
    }
}
