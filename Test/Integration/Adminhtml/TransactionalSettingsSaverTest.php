<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Adminhtml;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Smaily\Connect\Model\Adminhtml\TransactionalSettingsSaver;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Automation\MappingSaver;
use Smaily\Connect\Model\Client\CredentialCheck;
use Smaily\Connect\Model\Client\SmailyClientFactory;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\SubdomainNormalizer;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;

/**
 * The transactional-email account (PRO-4062) against a real
 * core_config_data table: saved once for all websites, read by every
 * website through Magento's website -> default fallback; a website with its
 * own account reads that one, and following the shared account again
 * removes the website's own rows.
 */
class TransactionalSettingsSaverTest extends IntegrationTestCase
{
    private Config $config;

    private TransactionalSettingsSaver $saver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = $this->objectManager->create(Config::class, [
            'scopeConfig' => new DatabaseScopeConfig($this->connection, [1 => 1, 2 => 2]),
        ]);
        $websiteContext = $this->createMock(WebsiteContext::class);
        $websiteContext->method('hasMultipleWebsites')->willReturn(true);

        $this->saver = new TransactionalSettingsSaver(
            $this->objectManager->get(WriterInterface::class),
            $this->objectManager->get(EncryptorInterface::class),
            new SubdomainNormalizer(),
            $this->config,
            $websiteContext,
            // Smaily is never asked: the client factory returns a mock.
            new CredentialCheck($this->createMock(SmailyClientFactory::class), $this->config),
            $this->objectManager->get(MappingSaver::class)
        );
    }

    public function testTheAccountSavedForAllWebsitesIsEveryWebsitesUntilOneUsesItsOwn(): void
    {
        $this->saver->saveAccount(
            ['enabled' => true, 'subdomain' => 'shop-tx', 'username' => 'api', 'password' => 'secret'],
            1
        );

        foreach ([1, 2] as $websiteId) {
            self::assertSame('shop-tx', $this->config->getTransactionalSubdomain($websiteId));
            self::assertSame('secret', $this->config->getTransactionalPassword($websiteId));
            self::assertFalse($this->config->hasTransactionalWebsiteAccount($websiteId));
        }
        $stored = $this->connection->fetchOne(
            $this->connection->select()->from('core_config_data', ['value'])
                ->where('path = ?', Config::XML_PATH_TRANSACTIONAL_PASSWORD)
        );
        self::assertNotSame('secret', $stored, 'The password is stored encrypted');

        $this->saver->saveAccount(
            [
                'enabled' => true,
                'subdomain' => 'ee-tx',
                'username' => 'api-ee',
                'password' => 'ee-secret',
                'website_account' => true,
            ],
            2
        );

        self::assertSame('shop-tx', $this->config->getTransactionalSubdomain(1));
        self::assertSame('ee-tx', $this->config->getTransactionalSubdomain(2));
        self::assertSame('ee-secret', $this->config->getTransactionalPassword(2));
        self::assertTrue($this->config->hasTransactionalWebsiteAccount(2));

        // The website follows the shared account again: its own rows go.
        $this->saver->saveAccount(
            ['enabled' => true, 'subdomain' => 'shop-tx', 'username' => 'api', 'password' => ''],
            2
        );

        self::assertSame('shop-tx', $this->config->getTransactionalSubdomain(2));
        self::assertSame('secret', $this->config->getTransactionalPassword(2));
        self::assertFalse($this->config->hasTransactionalWebsiteAccount(2));
        self::assertSame(
            ['default'],
            array_unique($this->connection->fetchCol(
                $this->connection->select()->from('core_config_data', ['scope'])
                    ->where('path LIKE ?', 'smaily_connect/transactional/%')
                    ->where('path <> ?', Config::XML_PATH_TRANSACTIONAL_ENABLED)
            ))
        );
    }

    /**
     * PRO-4065: "Send transactional emails" is off until switched on, per
     * website; switched off, the saved account stays.
     */
    public function testTheSwitchIsPerWebsiteAndOffKeepsTheAccount(): void
    {
        self::assertFalse($this->config->isTransactionalEnabled(1));
        $this->saver->saveAccount(
            ['enabled' => true, 'subdomain' => 'shop-tx', 'username' => 'api', 'password' => 'secret'],
            1
        );
        self::assertTrue($this->config->isTransactionalEnabled(1));
        self::assertFalse($this->config->isTransactionalEnabled(2), 'Another website stays off');

        $this->saver->saveAccount(['enabled' => false], 1);

        self::assertFalse($this->config->isTransactionalEnabled(1));
        self::assertSame('shop-tx', $this->config->getTransactionalSubdomain(1));
        self::assertSame('secret', $this->config->getTransactionalPassword(1));
    }

    public function testTheTriggersAreOffUntilSwitchedOnPerWebsite(): void
    {
        self::assertFalse($this->config->isOrderConfirmationEnabled(1));
        self::assertFalse($this->config->isShipmentConfirmationEnabled(1));
        self::assertFalse($this->config->isTransactionalPersonalDataEnabled(1));

        $this->saver->saveTriggers([
            'order_confirmation_enabled' => true,
            'shipment_confirmation_enabled' => false,
            'personal_data_enabled' => true,
        ], 2);

        self::assertTrue($this->config->isOrderConfirmationEnabled(2));
        self::assertFalse($this->config->isShipmentConfirmationEnabled(2));
        self::assertTrue($this->config->isTransactionalPersonalDataEnabled(2));
        self::assertFalse($this->config->isOrderConfirmationEnabled(1), 'Another website stays off');
        self::assertFalse($this->config->isTransactionalPersonalDataEnabled(1));
    }
}
