<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Engine;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Engine\EmailDesignWorkflows;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;

/**
 * CON-258: the pushed workflows a §23 read leaves behind live in
 * core_config_data at the default scope, so a later send (another
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
        $this->workflows()->remember([self::PUSHED]);

        self::assertSame(
            [['scope' => 'default', 'scope_id' => '0', 'value' => '{"plugin.abandoned_cart":{"workflow_id":456,'
                . '"subdomain":"shop"}}']],
            $this->rows()
        );
        self::assertSame(456, $this->workflows()->workflowId(Trigger::ABANDONED_CART, 'shop'));
    }

    public function testNullIdsLeaveNoRow(): void
    {
        $this->workflows()->remember([array_merge(self::PUSHED, [
            'smaily_state' => 'not_available',
            'smaily_workflow_id' => null,
            'smaily_subdomain' => null,
            'created_by' => null,
        ])]);

        self::assertSame([], $this->rows());
    }

    public function testANewSetupExchangeDeletesThem(): void
    {
        $this->workflows()->remember([self::PUSHED]);

        $this->settings()->storeExchange(['api_key' => 'sk_live_new', 'tenant_id' => 't_2']);

        self::assertSame([], $this->rows());
        self::assertSame(0, $this->workflows()->workflowId(Trigger::ABANDONED_CART, 'shop'));
    }

    public function testADisconnectDeletesThem(): void
    {
        $this->workflows()->remember([self::PUSHED]);

        $this->settings()->clear();

        self::assertSame([], $this->rows());
        self::assertSame(0, $this->workflows()->workflowId(Trigger::ABANDONED_CART, 'shop'));
    }

    private function workflows(): EmailDesignWorkflows
    {
        return new EmailDesignWorkflows(
            new DatabaseScopeConfig($this->connection, []),
            $this->objectManager->get(WriterInterface::class),
            $this->createMock(TypeListInterface::class),
            new Json()
        );
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
        );
    }
}
