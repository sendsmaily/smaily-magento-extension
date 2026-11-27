<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Engine;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Engine\EmailDesignWorkflows;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;

/**
 * CON-258: the pushed workflows a §23 read leaves behind live in
 * core_config_data at the scope of the connection that read them (the
 * default scope for the primary; CON-237), so a later send (another
 * request, the cron) reads them back; a new setup exchange and a
 * disconnect delete them. Real ConfigStorageWriter and core_config_data.
 */
class EmailDesignWorkflowsTest extends IntegrationTestCase
{
    private const PUSHED = [
        'email_key' => 'plugin.abandoned_cart',
        'kind' => 'plugin',
        'sent_through' => 'workflow',
        'smaily_state' => 'pushed',
        'smaily_workflow_id' => '456',
        'smaily_subdomain' => 'Shop',
        'created_by' => 'engine',
    ];

    public function testARememberedReadIsReadBackFromTheDatabase(): void
    {
        $this->workflows()->remember([self::PUSHED], $this->settings());

        self::assertSame(
            [['scope' => 'default', 'scope_id' => '0', 'value' => '{"plugin.abandoned_cart":{"workflow_id":456,'
                . '"subdomain":"shop"}}']],
            $this->rows()
        );
        self::assertSame(456, $this->workflows()->workflowId(Trigger::ABANDONED_CART, 'shop', $this->settings()));
    }

    public function testNullIdsLeaveNoRow(): void
    {
        $this->workflows()->remember([array_merge(self::PUSHED, [
            'smaily_state' => 'not_available',
            'smaily_workflow_id' => null,
            'smaily_subdomain' => null,
            'created_by' => null,
        ])], $this->settings());

        self::assertSame([], $this->rows());
    }

    public function testANewSetupExchangeDeletesThem(): void
    {
        $this->workflows()->remember([self::PUSHED], $this->settings());

        $this->settings()->storeExchange(['api_key' => 'sk_live_new', 'tenant_id' => 't_2']);

        self::assertSame([], $this->rows());
        self::assertSame(0, $this->workflows()->workflowId(Trigger::ABANDONED_CART, 'shop', $this->settings()));
    }

    public function testADisconnectDeletesThem(): void
    {
        $this->workflows()->remember([self::PUSHED], $this->settings());

        $this->settings()->clear();

        self::assertSame([], $this->rows());
        self::assertSame(0, $this->workflows()->workflowId(Trigger::ABANDONED_CART, 'shop', $this->settings()));
    }

    /**
     * CON-237: a website connection's read is kept at its website's scope
     * and read back from there, never the primary's; an empty read there
     * leaves '' rather than deleting the row, so the website never falls
     * back to the primary's workflows.
     */
    public function testAWebsiteConnectionKeepsItsOwnWorkflows(): void
    {
        $latvia = $this->settings()->withWebsite(2);
        $latvia->storeExchange(['api_key' => 'sk_live_lv', 'tenant_id' => 't_2'], 'shop');
        $this->workflows()->remember([self::PUSHED], $latvia);

        self::assertSame(
            [['scope' => 'websites', 'scope_id' => '2', 'value' => '{"plugin.abandoned_cart":{"workflow_id":456,'
                . '"subdomain":"shop"}}']],
            $this->rows()
        );
        self::assertSame(456, $this->workflows()->workflowId(Trigger::ABANDONED_CART, 'shop', $latvia));
        self::assertSame(0, $this->workflows()->workflowId(Trigger::ABANDONED_CART, 'shop', $this->settings()));

        $this->workflows()->remember([self::PUSHED], $this->settings());
        $this->workflows()->remember([], $this->settings()->withWebsite(2));

        self::assertSame(
            [
                ['scope' => 'default', 'scope_id' => '0', 'value' => '{"plugin.abandoned_cart":{"workflow_id":456,'
                    . '"subdomain":"shop"}}'],
                ['scope' => 'websites', 'scope_id' => '2', 'value' => ''],
            ],
            $this->rows()
        );
        self::assertSame(
            0,
            $this->workflows()->workflowId(Trigger::ABANDONED_CART, 'shop', $this->settings()->withWebsite(2))
        );
    }

    private function workflows(): EmailDesignWorkflows
    {
        return new EmailDesignWorkflows(new Json());
    }

    private function settings(): Settings
    {
        return $this->objectManager->create(Settings::class, [
            'scopeConfig' => new DatabaseScopeConfig($this->connection, []),
            'cacheTypeList' => $this->createMock(TypeListInterface::class),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->connection->fetchAll(
            $this->connection->select()
                ->from('core_config_data', ['scope', 'scope_id', 'value'])
                ->where('path = ?', Settings::XML_PATH_EMAIL_WORKFLOWS)
                ->order(['scope', 'scope_id'])
        );
    }
}
