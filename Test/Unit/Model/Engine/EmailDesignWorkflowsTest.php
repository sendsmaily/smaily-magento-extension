<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Engine\EmailDesignWorkflows;
use Smaily\Connect\Model\Engine\Settings;

/**
 * CON-258 (the WooCommerce plugin's EmailDesignWorkflows, CON-173 and
 * CON-218): which §23 items leave a pushed workflow behind, and on which
 * Smaily account a send may use it.
 */
class EmailDesignWorkflowsTest extends TestCase
{
    /** @var array<string, string> core_config_data at the default scope */
    private array $values = [];

    private int $writes = 0;

    /**
     * @var TypeListInterface&MockObject
     */
    private TypeListInterface $cacheTypeList;

    protected function setUp(): void
    {
        $this->values = [];
        $this->writes = 0;
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
    }

    public function testAPushedPluginOrTransactionalEmailKeepsItsWorkflowAndAccount(): void
    {
        $this->workflows()->remember([
            $this->item('plugin.welcome', ['smaily_workflow_id' => '456', 'smaily_subdomain' => 'shop']),
            $this->item('plugin.abandoned_cart', [
                'smaily_state' => 'pending',
                'smaily_workflow_id' => 457,
                'smaily_subdomain' => '  Shop-2 ',
            ]),
            // A failed push keeps the ids of the last pushed version (§23).
            $this->item('plugin.first_order', ['smaily_state' => 'failed', 'smaily_workflow_id' => '458']),
            $this->item('transactional.shipping_confirmation', [
                'kind' => 'transactional',
                'sent_through' => 'transactional',
                'smaily_workflow_id' => '460',
                'smaily_subdomain' => null,
            ]),
            $this->item('transactional.order_confirmation', [
                'kind' => 'transactional',
                'sent_through' => 'transactional',
                'smaily_workflow_id' => '459',
                'smaily_subdomain' => 'shop',
            ]),
        ]);

        self::assertSame(
            [
                'plugin.abandoned_cart' => ['workflow_id' => 457, 'subdomain' => 'shop-2'],
                'plugin.first_order' => ['workflow_id' => 458, 'subdomain' => 'shop'],
                'plugin.welcome' => ['workflow_id' => 456, 'subdomain' => 'shop'],
                'transactional.order_confirmation' => ['workflow_id' => 459, 'subdomain' => 'shop'],
            ],
            json_decode($this->values[Settings::XML_PATH_EMAIL_WORKFLOWS], true),
            'An id that names no account fits none, so the shipping confirmation keeps nothing'
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function itemsWithoutAWorkflow(): array
    {
        return [
            'null ids (the engine does not push yet)' => [[
                'smaily_state' => 'not_available',
                'smaily_workflow_id' => null,
                'smaily_subdomain' => null,
            ]],
            'not available' => [['smaily_state' => 'not_available']],
            'an unknown state' => [['smaily_state' => 'archived']],
            'no state' => [['smaily_state' => null]],
            'an engine email' => [['kind' => 'engine']],
            'an unknown kind' => [['kind' => 'newsletter']],
            'an unknown way of sending' => [['sent_through' => 'sms']],
            'no workflow id' => [['smaily_workflow_id' => null]],
            'a workflow id that is not a number' => [['smaily_workflow_id' => '45a']],
            'a zero workflow id' => [['smaily_workflow_id' => '0']],
            'no account' => [['smaily_subdomain' => null]],
            'an empty account' => [['smaily_subdomain' => '  ']],
            'an account that is no subdomain' => [['smaily_subdomain' => 'shop.sendsmaily.net']],
            'an email the extension does not send' => [['email_key' => 'plugin.follow_up']],
        ];
    }

    /**
     * @dataProvider itemsWithoutAWorkflow
     * @param array<string, mixed> $override
     */
    public function testAnItemWithoutAPushedWorkflowOnANamedAccountKeepsNothing(array $override): void
    {
        $this->workflows()->remember([$this->item('plugin.welcome', $override)]);

        self::assertArrayNotHasKey(Settings::XML_PATH_EMAIL_WORKFLOWS, $this->values);
        self::assertSame(0, $this->workflows()->workflowId(Trigger::WELCOME, 'shop'));
    }

    public function testAReadWithNullIdsWritesNothing(): void
    {
        $this->cacheTypeList->expects(self::never())->method('cleanType');

        $this->workflows()->remember([
            $this->item('plugin.welcome', ['smaily_state' => 'not_available', 'smaily_workflow_id' => null]),
            'not an item',
        ]);

        self::assertSame(0, $this->writes, 'Today\'s reads must not touch core_config_data or the config cache');
    }

    public function testAnUnchangedListIsNotWrittenAgain(): void
    {
        $this->workflows()->remember([$this->item('plugin.welcome')]);
        $this->workflows()->remember([$this->item('plugin.welcome', ['smaily_subdomain' => 'SHOP'])]);

        self::assertSame(1, $this->writes);
    }

    public function testEachReadReplacesTheLastOne(): void
    {
        $this->cacheTypeList->expects(self::exactly(2))->method('cleanType')->with('config');
        $this->workflows()->remember([$this->item('plugin.welcome')]);

        $this->workflows()->remember([$this->item('plugin.welcome', ['smaily_workflow_id' => null])]);

        self::assertArrayNotHasKey(Settings::XML_PATH_EMAIL_WORKFLOWS, $this->values);
        self::assertSame(0, $this->workflows()->workflowId(Trigger::WELCOME, 'shop'));
    }

    public function testASendUsesTheWorkflowOnlyOnTheNamedAccount(): void
    {
        $this->workflows()->remember([$this->item('plugin.welcome', ['smaily_subdomain' => 'Shop'])]);
        $workflows = $this->workflows();

        self::assertSame(456, $workflows->workflowId(Trigger::WELCOME, 'shop'));
        self::assertSame(456, $workflows->workflowId(Trigger::WELCOME, ' SHOP '), 'Case and spaces do not matter');
        self::assertSame(0, $workflows->workflowId(Trigger::WELCOME, 'other-shop'));
        self::assertSame(0, $workflows->workflowId(Trigger::WELCOME, ''));
        self::assertSame(0, $workflows->workflowId(Trigger::FIRST_ORDER, 'shop'), 'Each email has its own');
        self::assertSame(0, $workflows->workflowId('mystery', 'shop'));
    }

    public function testAStoredValueOfAnotherShapeCountsAsNone(): void
    {
        $this->values[Settings::XML_PATH_EMAIL_WORKFLOWS] = '{"plugin.welcome":456,"plugin.first_order":'
            . '{"workflow_id":"457","subdomain":"shop"}}';
        self::assertSame(0, $this->workflows()->workflowId(Trigger::WELCOME, 'shop'));
        self::assertSame(0, $this->workflows()->workflowId(Trigger::FIRST_ORDER, 'shop'));

        $this->values[Settings::XML_PATH_EMAIL_WORKFLOWS] = 'not json';
        self::assertSame(0, $this->workflows()->workflowId(Trigger::WELCOME, 'shop'));
    }

    /**
     * A pushed §23 item of plugin.welcome on the account "shop", with
     * `$override` on top.
     *
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function item(string $emailKey, array $override = []): array
    {
        return array_merge([
            'email_key' => $emailKey,
            'kind' => 'plugin',
            'sent_through' => 'workflow',
            'design_state' => 'edited',
            'smaily_state' => 'pushed',
            'smaily_template_id' => '123',
            'smaily_workflow_id' => '456',
            'smaily_subdomain' => 'shop',
            'created_by' => 'engine',
        ], $override);
    }

    private function workflows(): EmailDesignWorkflows
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path): ?string => $this->values[$path] ?? null
        );
        $writer = $this->createMock(WriterInterface::class);
        $writer->method('save')->willReturnCallback(function (string $path, string $value): void {
            $this->values[$path] = $value;
            $this->writes++;
        });
        $writer->method('delete')->willReturnCallback(function (string $path): void {
            unset($this->values[$path]);
            $this->writes++;
        });

        return new EmailDesignWorkflows($scopeConfig, $writer, $this->cacheTypeList, new Json());
    }
}
