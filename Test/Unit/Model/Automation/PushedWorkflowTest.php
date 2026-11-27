<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Automation;

use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Automation\PushedWorkflow;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Automation\WorkflowMatch;
use Smaily\Connect\Model\Engine\EmailDesignWorkflows;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * CON-258 (the WooCommerce plugin's Router::resolve_workflow(), CON-218):
 * an email starts with the workflow Campaign Intelligence pushed only when
 * the Smaily account that sends it holds that workflow and the picked
 * workflow is the website's default language; everything else keeps the
 * picked workflow.
 */
class PushedWorkflowTest extends TestCase
{
    use EngineConnectionsStub;

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
     * CON-237: separate stores — a send reads the pushed workflows of the
     * connection serving its website; a website no connection serves keeps
     * the picked workflow.
     */
    public function testTheConnectionOfTheEmailsWebsiteHoldsItsPushedWorkflows(): void
    {
        $latvia = $this->createMock(Settings::class);
        $latvia->method('getEmailWorkflows')->willReturn('{"plugin.welcome":{"workflow_id":789,"subdomain":"shop"}}');
        $connections = $this->engineConnections([
            [$this->engineConnection(0, $this->settingsStoring(self::STORED), [1], [1], 1), null],
            [$this->engineConnection(2, $latvia, [2], [2], 2), null],
        ]);

        self::assertSame(self::PUSHED, $this->send(Trigger::WELCOME, 'default', 'shop', connections: $connections));
        self::assertSame(789, $this->send(Trigger::WELCOME, 'default', 'shop', connections: $connections, websiteId: 2));
        self::assertSame(
            self::PICKED,
            $this->send(Trigger::WELCOME, 'default', 'shop', connections: $connections, websiteId: 3)
        );
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
        ?string $stored = self::STORED,
        ?Connections $connections = null,
        int $websiteId = 1
    ): int {
        $pushedWorkflow = new PushedWorkflow(
            new EmailDesignWorkflows(new Json()),
            $connections ?? $this->oneEngineConnection($this->settingsStoring($stored))
        );

        return $pushedWorkflow->workflowId(
            $trigger,
            new WorkflowMatch(self::PICKED, null, $language),
            $subdomain,
            $defaultLanguage,
            $websiteId
        );
    }

    /** A connection's settings holding the pushed workflows `$stored` (null: nothing pushed). */
    private function settingsStoring(?string $stored): Settings
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('getEmailWorkflows')->willReturn($stored ?? '');

        return $settings;
    }
}
