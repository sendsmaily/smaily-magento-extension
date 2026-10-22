<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Automation;

use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * CON-184: a website that switches between per-language Smaily accounts
 * (mode A) and one account with per-language workflows (mode B) keeps both
 * modes' rows in smaily_automation_mapping, and the Router reads only the
 * active mode's. A mode-A workflow id belongs to a language's own Smaily
 * account, so mode B never posts it through the website's one account; a
 * switch back to mode A finds its rows again, without mode B's.
 */
class RouterModeRowsTest extends IntegrationTestCase
{
    private const WEBSITE_ID = 1;

    private const MODE_A_ET = 101;
    private const MODE_A_LV = 102;
    private const MODE_A_FIRST_ORDER = 103;
    private const MODE_B_ET = 201;
    private const MODE_B_FALLBACK = 202;
    private const CONFIG_DEFAULT = 9;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = $this->objectManager->create(Router::class, [
            'overrides' => $this->createMock(WebsiteOverrides::class),
        ]);
        $this->env->getScopeConfig()->setValue(Config::XML_PATH_WELCOME_WORKFLOW, (string)self::CONFIG_DEFAULT);

        // Saved in mode A: each language's own account, ET the fallback.
        $this->insertRow(Trigger::WELCOME, 'et', 'et', self::MODE_A_ET, true);
        $this->insertRow(Trigger::WELCOME, 'lv', 'lv', self::MODE_A_LV);
        $this->insertRow(Trigger::FIRST_ORDER, 'et', 'et', self::MODE_A_FIRST_ORDER, true);
        // Saved in mode B afterwards: the website's one account, ET only,
        // LV the fallback.
        $this->insertRow(Trigger::WELCOME, 'et', 'default', self::MODE_B_ET);
        $this->insertRow(Trigger::WELCOME, 'lv', 'default', self::MODE_B_FALLBACK, true);
    }

    public function testModeBNeverStartsAWorkflowSavedForThePerLanguageAccounts(): void
    {
        $this->env->getScopeConfig()->setValue(Config::XML_PATH_MULTILINGUAL_MODE, 'b');
        $modeAIds = [self::MODE_A_ET, self::MODE_A_LV, self::MODE_A_FIRST_ORDER];

        $et = $this->router->resolve(Trigger::WELCOME, self::WEBSITE_ID, 'et');
        self::assertNotNull($et);
        self::assertSame(self::MODE_B_ET, $et->workflowId, 'Mode B uses the workflow mapped in mode B');
        self::assertSame('default', $et->accountKey);

        $ru = $this->router->resolve(Trigger::WELCOME, self::WEBSITE_ID, 'ru');
        self::assertNotNull($ru);
        self::assertSame(self::MODE_B_FALLBACK, $ru->workflowId, 'Mode B\'s own fallback row, not mode A\'s');

        $firstOrder = $this->router->resolve(Trigger::FIRST_ORDER, self::WEBSITE_ID, 'et');
        self::assertNull($firstOrder, 'A trigger mapped only in mode A has no workflow in mode B');

        foreach ([[Trigger::WELCOME, 'et'], [Trigger::WELCOME, 'lv'], [Trigger::WELCOME, 'ru'],
                     [Trigger::FIRST_ORDER, 'et'], [Trigger::FIRST_ORDER, 'lv']] as [$trigger, $language]) {
            $match = $this->router->resolve($trigger, self::WEBSITE_ID, $language);
            self::assertNotContains(
                $match?->workflowId,
                $modeAIds,
                sprintf('%s/%s must not start a workflow of a per-language account', $trigger, $language)
            );
        }
    }

    public function testASwitchBackToModeAFindsItsOwnRowsAgain(): void
    {
        $this->env->getScopeConfig()->setValue(Config::XML_PATH_MULTILINGUAL_MODE, 'a');

        $et = $this->router->resolve(Trigger::WELCOME, self::WEBSITE_ID, 'et');
        self::assertNotNull($et);
        self::assertSame(self::MODE_A_ET, $et->workflowId);
        self::assertSame('et', $et->accountKey, 'The workflow goes through the account it was picked from');

        $lv = $this->router->resolve(Trigger::WELCOME, self::WEBSITE_ID, 'lv');
        self::assertNotNull($lv);
        self::assertSame(self::MODE_A_LV, $lv->workflowId);

        $ru = $this->router->resolve(Trigger::WELCOME, self::WEBSITE_ID, 'ru');
        self::assertNotNull($ru);
        self::assertSame(self::MODE_A_ET, $ru->workflowId, 'Mode A\'s own fallback row, not mode B\'s');
        self::assertSame('et', $ru->accountKey);
    }

    public function testModeAStillReadsTheMigratedFallbackRow(): void
    {
        $this->env->getScopeConfig()->setValue(Config::XML_PATH_MULTILINGUAL_MODE, 'a');
        // The 2.8.x migration's seed: no language, the default account.
        $this->connection->insert(MappingResource::TABLE_NAME, [
            'website_id' => 0,
            'trigger_type' => Trigger::ABANDONED_CART,
            'language' => 'default',
            'account_key' => 'default',
            'workflow_id' => 900,
            'is_default_fallback' => 1,
        ]);

        $match = $this->router->resolve(Trigger::ABANDONED_CART, self::WEBSITE_ID, 'et');

        self::assertNotNull($match);
        self::assertSame(900, $match->workflowId);
        self::assertSame('default', $match->accountKey);
    }

    private function insertRow(
        string $trigger,
        string $language,
        string $accountKey,
        int $workflowId,
        bool $fallback = false
    ): void {
        $this->connection->insert(MappingResource::TABLE_NAME, [
            'website_id' => self::WEBSITE_ID,
            'trigger_type' => $trigger,
            'language' => $language,
            'account_key' => $accountKey,
            'workflow_id' => $workflowId,
            'is_default_fallback' => $fallback ? 1 : 0,
        ]);
    }
}
