<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Automation;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Automation\ConfigRowNormalizer;

class ConfigRowNormalizerTest extends TestCase
{
    private ConfigRowNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ConfigRowNormalizer();
    }

    /**
     * PRO-1268: the saved single-mode workflow is absent from the freshly
     * loaded Smaily list, so the dropdown rendered no option for it and posted
     * an empty workflow_id. That must NOT wipe the binding.
     */
    public function testSavedIdMissingFromListIsPreserved(): void
    {
        $row = $this->normalizer->normalize(
            'welcome',
            [
                'workflow_id' => '',
                'language_mode' => 'single',
                'original_map' => '{"id":"123"}',
                'enabled' => '1',
            ],
            ['456', '789']
        );

        self::assertSame(['id' => '123'], $row['automation_map']);
        self::assertSame('single', $row['language_mode']);
        self::assertTrue($row['enabled']);
    }

    /**
     * The list failed to load entirely (empty). Every saved id is unconfirmed,
     * so the binding is kept rather than dropped on a blind save.
     */
    public function testSavedIdPreservedWhenListFailedToLoad(): void
    {
        $row = $this->normalizer->normalize(
            'abandoned_cart',
            [
                'workflow_id' => '',
                'language_mode' => 'single',
                'original_map' => '{"id":"555"}',
                'enabled' => '1',
            ],
            []
        );

        self::assertSame(['id' => '555'], $row['automation_map']);
    }

    /**
     * The saved id IS in the list and the merchant cleared the select to
     * "-- Not Selected --": that is a real clear and is honored.
     */
    public function testDeliberateClearOfPresentIdIsHonored(): void
    {
        $row = $this->normalizer->normalize(
            'welcome',
            [
                'workflow_id' => '',
                'language_mode' => 'single',
                'original_map' => '{"id":"123"}',
                'enabled' => '1',
            ],
            ['123', '456']
        );

        self::assertInstanceOf(\stdClass::class, $row['automation_map']);
        self::assertFalse($row['enabled'], 'An empty map cannot be enabled.');
    }

    public function testNewSelectionIsStored(): void
    {
        $row = $this->normalizer->normalize(
            'first_order',
            [
                'workflow_id' => '456',
                'language_mode' => 'single',
                'original_map' => '{}',
                'enabled' => '1',
            ],
            ['456', '789']
        );

        self::assertSame(['id' => '456'], $row['automation_map']);
        self::assertTrue($row['enabled']);
    }

    /**
     * A per_language map (saved from another platform) is kept whole as long as
     * the merchant did not change the fallback workflow.
     */
    public function testPerLanguageMapPreservedWhenFallbackUnchanged(): void
    {
        $map = ['fallback' => '999', 'en' => '111', 'et' => '222'];
        $row = $this->normalizer->normalize(
            'welcome',
            [
                'workflow_id' => '999',
                'language_mode' => 'per_language',
                'original_map' => json_encode($map),
                'enabled' => '1',
            ],
            ['999']
        );

        self::assertSame($map, $row['automation_map']);
        self::assertSame('per_language', $row['language_mode']);
    }

    /**
     * PRO-1286: the per_language fallback workflow is missing from the freshly
     * loaded Smaily list, so the single select rendered no option and posted an
     * empty workflow_id. The whole per-language map must be kept, not wiped.
     */
    public function testPerLanguageMapPreservedWhenMissingFallbackPostsEmpty(): void
    {
        $map = ['fallback' => '999', 'en' => '111', 'et' => '222'];
        $row = $this->normalizer->normalize(
            'welcome',
            [
                'workflow_id' => '',
                'language_mode' => 'per_language',
                'original_map' => json_encode($map),
                'enabled' => '1',
            ],
            ['456', '789']
        );

        self::assertSame($map, $row['automation_map']);
        self::assertSame('per_language', $row['language_mode']);
    }

    /**
     * PRO-1286: the list failed to load entirely — every saved id counts as
     * missing, so the per-language map survives a blind save.
     */
    public function testPerLanguageMapPreservedWhenListFailedToLoad(): void
    {
        $map = ['fallback' => '999', 'en' => '111'];
        $row = $this->normalizer->normalize(
            'welcome',
            [
                'workflow_id' => '',
                'language_mode' => 'per_language',
                'original_map' => json_encode($map),
                'enabled' => '1',
            ],
            []
        );

        self::assertSame($map, $row['automation_map']);
        self::assertSame('per_language', $row['language_mode']);
    }

    /**
     * PRO-1286: the per_language fallback id IS in the list and the merchant
     * cleared the select — a deliberate clear collapses to an empty single map.
     */
    public function testClearingPerLanguageFallbackThatIsPresentIsHonored(): void
    {
        $map = ['fallback' => '999', 'en' => '111'];
        $row = $this->normalizer->normalize(
            'welcome',
            [
                'workflow_id' => '',
                'language_mode' => 'per_language',
                'original_map' => json_encode($map),
                'enabled' => '1',
            ],
            ['999', '456']
        );

        self::assertInstanceOf(\stdClass::class, $row['automation_map']);
        self::assertSame('single', $row['language_mode']);
        self::assertFalse($row['enabled'], 'An empty map cannot be enabled.');
    }

    /**
     * The shared decision helper is the one place the "keep a saved id when the
     * post is empty and the id is missing from the list" rule lives (reused by
     * the wizard/Settings selects and the mapping editor). Empty list = unknown
     * = missing; a present id cleared is not missing.
     */
    public function testIsMissingFromListDecision(): void
    {
        self::assertTrue($this->normalizer->isMissingFromList('', '123', ['456']));
        self::assertTrue($this->normalizer->isMissingFromList('', '123', []), 'Empty list = unknown = missing');
        self::assertFalse($this->normalizer->isMissingFromList('', '123', ['123']), 'Present id cleared = honest clear');
        self::assertFalse($this->normalizer->isMissingFromList('456', '123', ['789']), 'A non-empty post is a real choice');
        self::assertFalse($this->normalizer->isMissingFromList('', '', ['456']), 'No saved id, nothing to preserve');
    }

    public function testChangingPerLanguageFallbackCollapsesToSingle(): void
    {
        $map = ['fallback' => '999', 'en' => '111'];
        $row = $this->normalizer->normalize(
            'welcome',
            [
                'workflow_id' => '456',
                'language_mode' => 'per_language',
                'original_map' => json_encode($map),
                'enabled' => '1',
            ],
            ['456', '999']
        );

        self::assertSame(['id' => '456'], $row['automation_map']);
        self::assertSame('single', $row['language_mode']);
    }

    public function testNumericFieldsAreClamped(): void
    {
        $row = $this->normalizer->normalize(
            'welcome',
            [
                'workflow_id' => '10',
                'cooldown_days' => '9999',
                'daily_cap' => '0',
                'test_emails' => 'a@example.com, , b@example.com ',
            ],
            ['10']
        );

        self::assertSame(365, $row['cooldown_days']);
        self::assertSame(1, $row['daily_cap']);
        self::assertSame(['a@example.com', 'b@example.com'], $row['test_emails']);
    }
}
