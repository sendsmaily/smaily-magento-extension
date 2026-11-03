<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Automation;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Automation\PushedWorkflow;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Automation\WorkflowMatch;
use Smaily\Connect\Model\Engine\EmailDesignWorkflows;
use Smaily\Connect\Model\Engine\Settings;

/**
 * CON-258 (the WooCommerce plugin's Router::resolve_workflow(), CON-218):
 * an email starts with the workflow Campaign Intelligence pushed only when
 * the Smaily account that sends it holds that workflow and the picked
 * workflow is the website's default language; everything else keeps the
 * picked workflow.
 */
class PushedWorkflowTest extends TestCase
{
    private const PICKED = 7;
    private const PUSHED = 456;

    /** Campaign Intelligence pushed plugin.welcome to the account "shop". */
    private const STORED = '{"plugin.welcome":{"workflow_id":456,"subdomain":"shop"}}';

    /** The website's default store view is Estonian. */
    private const ESTONIAN = 'et';

    public function testWithoutAPushedWorkflowTheEmailKeepsThePickedOne(): void
    {
        self::assertSame(self::PICKED, $this->send(Trigger::WELCOME, 'default', 'shop', self::ESTONIAN, null));
    }

    /**
     * The one workflow of modes single and c, a language-less row and mode
     * b's catch-all for a contact without a language are all 'default'.
     */
    public function testTheOneWorkflowForEveryLanguageTakesThePushedOneOnTheNamedAccount(): void
    {
        self::assertSame(self::PUSHED, $this->send(Trigger::WELCOME, 'default', 'shop'));
    }

    public function testTheAccountIsComparedTrimmedAndWithoutCase(): void
    {
        self::assertSame(self::PUSHED, $this->send(Trigger::WELCOME, 'default', ' SHOP '));
    }

    public function testTheWebsitesDefaultLanguageTakesThePushedOne(): void
    {
        self::assertSame(self::PUSHED, $this->send(Trigger::WELCOME, 'et', 'shop'));
    }

    public function testAnotherAccountKeepsThePickedWorkflow(): void
    {
        self::assertSame(
            self::PICKED,
            $this->send(Trigger::WELCOME, 'default', 'other-shop'),
            'Smaily numbers workflows per account: the id is not this account\'s'
        );
    }

    public function testAnotherLanguageOnTheNamedAccountKeepsThePickedWorkflow(): void
    {
        self::assertSame(
            self::PICKED,
            $this->send(Trigger::WELCOME, 'en', 'shop'),
            'The pushed design may be written in one language'
        );
    }

    public function testAMatchOfUnknownLanguageKeepsThePickedWorkflow(): void
    {
        self::assertSame(self::PICKED, $this->send(Trigger::WELCOME, null, 'shop'));
    }

    public function testAWebsiteWithoutADefaultLanguageTakesItOnlyForTheOneWorkflow(): void
    {
        self::assertSame(self::PICKED, $this->send(Trigger::WELCOME, 'et', 'shop', ''));
        self::assertSame(self::PUSHED, $this->send(Trigger::WELCOME, 'default', 'shop', ''));
    }

    public function testEachEmailTakesOnlyItsOwnPushedWorkflow(): void
    {
        self::assertSame(self::PICKED, $this->send(Trigger::FIRST_ORDER, 'default', 'shop'));
    }

    /**
     * The workflow a send posts: the picked workflow matched for
     * `$language`, sent through the account `$subdomain` on a website whose
     * default language is `$defaultLanguage`, with the pushed workflows
     * stored as `$stored` (null: nothing pushed).
     */
    private function send(
        string $trigger,
        ?string $language,
        string $subdomain,
        string $defaultLanguage = self::ESTONIAN,
        ?string $stored = self::STORED
    ): int {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string => $path === Settings::XML_PATH_EMAIL_WORKFLOWS ? $stored : null
        );
        $pushedWorkflow = new PushedWorkflow(new EmailDesignWorkflows(
            $scopeConfig,
            $this->createMock(WriterInterface::class),
            $this->createMock(TypeListInterface::class),
            new Json()
        ));

        return $pushedWorkflow->workflowId(
            $trigger,
            new WorkflowMatch(self::PICKED, null, $language),
            $subdomain,
            $defaultLanguage
        );
    }
}
