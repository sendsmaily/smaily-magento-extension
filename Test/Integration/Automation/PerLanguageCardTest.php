<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Automation;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Adminhtml\SetupGuard;
use Smaily\Connect\Model\Adminhtml\SharedAccountModes;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;
use Smaily\Connect\Model\Automation\MappingSaver;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Backfill\ContactAudience;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\Discount\RuleSource;
use Smaily\Connect\Model\Discount\StoreDate;
use Smaily\Connect\Model\Engine\ConsentSource;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\OrderOrigin;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping\CollectionFactory as MappingCollectionFactory;
use Smaily\Connect\Model\StoreLayout;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\ViewModel\Adminhtml\WizardData;

/**
 * CON-461: in a per-language mode each store-event automation is one card
 * with a workflow per store language and a Default fallback on one of the
 * rows; the "None" row and the card's own workflow are gone from it. No
 * stored row is rewritten by the change: each shape saved before it routes
 * as it did, the card shows each language the workflow it gets, and a save
 * from the card — what the Automations tab posts — keeps every store
 * language's workflow and the workflow of a contact without a store
 * language.
 *
 * Each shape is seeded, routed through the real Router (pinned to the
 * routing it had before the card), read into the card, saved from it the
 * way the Settings and setup Automations save does, and routed again.
 */
class PerLanguageCardTest extends IntegrationTestCase
{
    private const WEBSITE_ID = 1;
    private const LANGUAGES = ['et', 'en', 'lv'];

    /** A language with no workflow of its own: none ('') and one not on the website. */
    private const UNKNOWN = ['', 'ru'];

    private Router $router;
    private WizardData $viewModel;

    protected function setUp(): void
    {
        parent::setUp();
        $overrides = $this->createMock(WebsiteOverrides::class);
        $this->router = $this->objectManager->create(Router::class, ['overrides' => $overrides]);

        $defaultStore = $this->createMock(StoreInterface::class);
        $defaultStore->method('getWebsiteId')->willReturn(self::WEBSITE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($defaultStore);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturn(null);
        $accountResolver = $this->createMock(AccountResolver::class);
        $accountResolver->method('detectedLanguages')->with(self::WEBSITE_ID)->willReturn(self::LANGUAGES);

        $this->viewModel = new WizardData(
            $this->objectManager->get(Config::class),
            $this->createMock(Mode::class),
            $this->createMock(EngineSettings::class),
            $accountResolver,
            $this->createMock(ContactAudience::class),
            $this->objectManager->get(Json::class),
            $this->objectManager->get(MappingCollectionFactory::class),
            new WebsiteContext($storeManager, $request),
            $this->createMock(VerifiedCredentials::class),
            $this->createMock(ResolverInterface::class),
            $this->createMock(ConsentSource::class),
            $this->createMock(OrderOrigin::class),
            $this->createMock(SetupGuard::class),
            $overrides,
            $this->createMock(RuleSource::class),
            $this->createMock(StoreDate::class),
            $this->createMock(StoreLayout::class),
            $this->createMock(SharedAccountModes::class),
            $this->router
        );
    }

    /**
     * One account, per-language automations (mode b): the shapes the editor
     * with a "None" row could save.
     *
     * @return array<string, array{int, array<int, array{int, string, int, bool}>,
     *     array<string, int>, array{workflows: array<string, int>, fallback: string}}>
     */
    public static function modeBShapes(): array
    {
        return [
            // [card workflow, rows [website, language, workflow, fallback],
            //  routing by language ('' and 'ru' have none of their own), card]
            '"None" falls back to the card\'s workflow' => [
                9,
                [[1, 'et', 11, false]],
                ['et' => 11, 'en' => 9, 'lv' => 9, '' => 9, 'ru' => 9],
                ['workflows' => ['et' => 11, 'en' => 9, 'lv' => 9], 'fallback' => 'en'],
            ],
            'a language row is the fallback' => [
                9,
                [[1, 'et', 11, false], [1, 'en', 12, true]],
                ['et' => 11, 'en' => 12, 'lv' => 12, '' => 12, 'ru' => 12],
                ['workflows' => ['et' => 11, 'en' => 12, 'lv' => 12], 'fallback' => 'en'],
            ],
            'the first language is the fallback' => [
                9,
                [[1, 'et', 11, true], [1, 'en', 12, false]],
                ['et' => 11, 'en' => 12, 'lv' => 11, '' => 11, 'ru' => 11],
                ['workflows' => ['et' => 11, 'en' => 12, 'lv' => 11], 'fallback' => 'et'],
            ],
            '"None" with no card workflow' => [
                0,
                [[1, 'et', 11, false]],
                ['et' => 11, 'en' => 0, 'lv' => 0, '' => 0, 'ru' => 0],
                ['workflows' => ['et' => 11, 'en' => 0, 'lv' => 0], 'fallback' => 'en'],
            ],
            'the 2.8.x migration\'s store-wide row' => [
                9,
                [[0, 'default', 9, true]],
                ['et' => 9, 'en' => 9, 'lv' => 9, '' => 9, 'ru' => 9],
                ['workflows' => ['et' => 9, 'en' => 9, 'lv' => 9], 'fallback' => 'et'],
            ],
            'store-wide rows the website reads' => [
                9,
                [[0, 'et', 11, false], [0, 'lv', 13, true]],
                ['et' => 11, 'en' => 13, 'lv' => 13, '' => 13, 'ru' => 13],
                ['workflows' => ['et' => 11, 'en' => 13, 'lv' => 13], 'fallback' => 'lv'],
            ],
            'a cleared row hides the store-wide fallback (CON-396)' => [
                9,
                [[0, 'et', 11, true], [1, 'et', 0, false]],
                ['et' => 9, 'en' => 9, 'lv' => 9, '' => 9, 'ru' => 9],
                ['workflows' => ['et' => 9, 'en' => 9, 'lv' => 9], 'fallback' => 'et'],
            ],
        ];
    }

    /**
     * @dataProvider modeBShapes
     * @param array<int, array{int, string, int, bool}> $rows
     * @param array<string, int> $routing
     * @param array{workflows: array<string, int>, fallback: string} $card
     */
    public function testModeBShapesRouteAsBeforeAndAfterASaveFromTheCard(
        int $cardWorkflow,
        array $rows,
        array $routing,
        array $card
    ): void {
        $this->env->getScopeConfig()->setValue(Config::XML_PATH_MULTILINGUAL_MODE, 'b');
        $this->env->getScopeConfig()->setValue(Config::XML_PATH_WELCOME_WORKFLOW, (string)$cardWorkflow);
        foreach ($rows as [$websiteId, $language, $workflowId, $fallback]) {
            $this->insertRow($websiteId, $language, 'default', $workflowId, $fallback);
        }

        self::assertSame($routing, $this->routing(), 'The shape routes as it did before the card');
        $shown = $this->viewModel->getLanguageWorkflows('b')[Trigger::WELCOME];
        self::assertSame($card, $shown, 'The card shows each language the workflow it gets');

        $this->saveFromCard($shown, 'b');

        self::assertSame($routing, $this->routing(), 'A save from the card keeps every workflow');
    }

    /**
     * The one shape the card cannot hold: every store language has its own
     * workflow, and "None" sends a contact without a store language the
     * card's workflow, which no language gets. Until a save nothing changes;
     * a save from the card makes the first language's row the fallback.
     */
    public function testEveryLanguageMappedWithNoneGivesAContactWithoutALanguageTheFirstRowAfterASave(): void
    {
        $this->env->getScopeConfig()->setValue(Config::XML_PATH_MULTILINGUAL_MODE, 'b');
        $this->env->getScopeConfig()->setValue(Config::XML_PATH_WELCOME_WORKFLOW, '9');
        foreach (['et' => 11, 'en' => 12, 'lv' => 13] as $language => $workflowId) {
            $this->insertRow(self::WEBSITE_ID, $language, 'default', $workflowId, false);
        }
        $before = ['et' => 11, 'en' => 12, 'lv' => 13, '' => 9, 'ru' => 9];
        self::assertSame($before, $this->routing());

        $shown = $this->viewModel->getLanguageWorkflows('b')[Trigger::WELCOME];
        self::assertSame(['workflows' => ['et' => 11, 'en' => 12, 'lv' => 13], 'fallback' => 'et'], $shown);
        $this->saveFromCard($shown, 'b');

        self::assertSame(['et' => 11, 'en' => 12, 'lv' => 13, '' => 11, 'ru' => 11], $this->routing());
    }

    /**
     * Separate Smaily accounts (mode a): each row lists its own
     * account's workflows, so a language shows only its own row; "None"
     * used the default fallback account's language, where the card's
     * Default fallback now starts.
     *
     * @return array<string, array{string, array<int, array{string, int, bool}>,
     *     array<string, string>, array{workflows: array<string, int>, fallback: string}}>
     */
    public static function modeAShapes(): array
    {
        return [
            // [fallback account's language, rows [language, workflow, fallback],
            //  routing by language as "workflow@account", card]
            'a language row is the fallback' => [
                'lv',
                [['et', 101, true], ['lv', 102, false]],
                ['et' => '101@et', 'en' => '101@et', 'lv' => '102@lv', '' => '101@et', 'ru' => '101@et'],
                ['workflows' => ['et' => 101, 'en' => 0, 'lv' => 102], 'fallback' => 'et'],
            ],
            '"None" uses the fallback account\'s language' => [
                'lv',
                [['et', 101, false], ['lv', 102, false]],
                ['et' => '101@et', 'en' => '102@lv', 'lv' => '102@lv', '' => '102@lv', 'ru' => '102@lv'],
                ['workflows' => ['et' => 101, 'en' => 0, 'lv' => 102], 'fallback' => 'lv'],
            ],
            '"None" where the fallback account\'s language has no workflow' => [
                'lv',
                [['et', 101, false]],
                ['et' => '101@et', 'en' => '', 'lv' => '', '' => '', 'ru' => ''],
                ['workflows' => ['et' => 101, 'en' => 0, 'lv' => 0], 'fallback' => 'lv'],
            ],
        ];
    }

    /**
     * @dataProvider modeAShapes
     * @param array<int, array{string, int, bool}> $rows
     * @param array<string, string> $routing
     * @param array{workflows: array<string, int>, fallback: string} $card
     */
    public function testModeAShapesRouteAsBeforeAndAfterASaveFromTheCard(
        string $fallbackLanguage,
        array $rows,
        array $routing,
        array $card
    ): void {
        $this->env->getScopeConfig()->setValue(Config::XML_PATH_MULTILINGUAL_MODE, 'a');
        $this->env->getScopeConfig()->setValue(Config::XML_PATH_FALLBACK_LANGUAGE, $fallbackLanguage);
        $this->env->getScopeConfig()->setValue(Config::XML_PATH_WELCOME_WORKFLOW, '9');
        foreach ($rows as [$language, $workflowId, $fallback]) {
            $this->insertRow(self::WEBSITE_ID, $language, $language, $workflowId, $fallback);
        }

        self::assertSame($routing, $this->routing(true), 'The shape routes as it did before the card');
        $shown = $this->viewModel->getLanguageWorkflows('a')[Trigger::WELCOME];
        self::assertSame($card, $shown, 'The card shows each language its own row');

        $this->saveFromCard($shown, 'a');

        self::assertSame($routing, $this->routing(true), 'A save from the card keeps every workflow');
    }

    /**
     * The Welcome workflow each language gets: its id, or with `$account`
     * "id@account" ('' for none).
     *
     * @return array<string, int|string>
     */
    private function routing(bool $account = false): array
    {
        $routing = [];
        foreach (array_merge(self::LANGUAGES, self::UNKNOWN) as $language) {
            $match = $this->router->resolve(Trigger::WELCOME, self::WEBSITE_ID, $language);
            $routing[$language] = $account
                ? ($match === null ? '' : $match->workflowId . '@' . $match->accountKey)
                : ($match->workflowId ?? 0);
        }

        return $routing;
    }

    /**
     * What the card's save posts (panels-js.phtml collect.automations()):
     * a row per language with a workflow, through the mode's account, the
     * Default fallback's flagged; saved for the website as Settings saves
     * it (WizardStepSaver::saveAutomations()).
     *
     * @param array{workflows: array<string, int>, fallback: string} $card
     */
    private function saveFromCard(array $card, string $mode): void
    {
        $rows = [];
        foreach ($card['workflows'] as $language => $workflowId) {
            if ($workflowId > 0) {
                $rows[] = [
                    'trigger_type' => Trigger::WELCOME,
                    'language' => $language,
                    'account_key' => $mode === 'a' ? $language : 'default',
                    'workflow_id' => $workflowId,
                    'is_default_fallback' => $language === $card['fallback'],
                ];
            }
        }

        self::assertSame(
            [],
            $this->objectManager->get(MappingSaver::class)->save($rows, self::WEBSITE_ID, null, $mode, true)
        );
    }

    private function insertRow(
        int $websiteId,
        string $language,
        string $accountKey,
        int $workflowId,
        bool $fallback
    ): void {
        $this->connection->insert(MappingResource::TABLE_NAME, [
            'website_id' => $websiteId,
            'trigger_type' => Trigger::WELCOME,
            'language' => $language,
            'account_key' => $accountKey,
            'workflow_id' => $workflowId,
            'is_default_fallback' => $fallback ? 1 : 0,
        ]);
    }
}
