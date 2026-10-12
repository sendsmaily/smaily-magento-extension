<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Adminhtml;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Adminhtml\TransactionalSettingsSaver;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Automation\MappingSaver;
use Smaily\Connect\Model\Client\CredentialCheck;
use Smaily\Connect\Model\Client\Exception\AuthenticationException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientFactory;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * The transactional-email settings of Settings (PRO-4062): the separate
 * account saved for all websites (default scope) with an optional website
 * account, its password stored encrypted, behind the "Send transactional
 * emails" switch (PRO-4065), and the two triggers and Customer details off
 * unless switched on.
 */
class TransactionalSettingsSaverTest extends TestCase
{
    private const WEBSITE_ID = 3;

    /** @var Config&\PHPUnit\Framework\MockObject\MockObject */
    private $config;

    /** @var WebsiteContext&\PHPUnit\Framework\MockObject\MockObject */
    private $websiteContext;

    /** @var MappingSaver&\PHPUnit\Framework\MockObject\MockObject */
    private $mappingSaver;

    /** @var SmailyClient&\PHPUnit\Framework\MockObject\MockObject */
    private $client;

    /** @var array<string, array{value: mixed, scope: string, scopeId: int}> path@scope/id => write */
    private array $saved = [];

    /** @var string[] path@scope/id */
    private array $deleted = [];

    private TransactionalSettingsSaver $saver;

    protected function setUp(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->method('save')->willReturnCallback(
            function (
                string $path,
                $value,
                string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                int $scopeId = 0
            ) use ($writer): WriterInterface {
                $this->saved[$path . '@' . $scope . '/' . $scopeId] = $value;

                return $writer;
            }
        );
        $writer->method('delete')->willReturnCallback(
            function (
                string $path,
                string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                int $scopeId = 0
            ) use ($writer): WriterInterface {
                $this->deleted[] = $path . '@' . $scope . '/' . $scopeId;

                return $writer;
            }
        );
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(static fn (string $value): string => 'enc:' . $value);
        $normalizer = $this->createMock(SubdomainNormalizer::class);
        $normalizer->method('normalize')->willReturnCallback(static fn (string $value): string => trim($value));
        $this->config = $this->createMock(Config::class);
        $this->websiteContext = $this->createMock(WebsiteContext::class);
        $this->mappingSaver = $this->createMock(MappingSaver::class);
        $this->client = $this->createMock(SmailyClient::class);
        $clientFactory = $this->createMock(SmailyClientFactory::class);
        $clientFactory->method('create')->willReturn($this->client);

        $this->saver = new TransactionalSettingsSaver(
            $writer,
            $encryptor,
            $normalizer,
            $this->config,
            $this->websiteContext,
            new CredentialCheck($clientFactory, $this->config),
            $this->mappingSaver
        );
    }

    public function testTheAccountIsSavedForAllWebsitesWithItsPasswordEncrypted(): void
    {
        $account = ['enabled' => true, 'subdomain' => 'shop-tx', 'username' => 'api', 'password' => 'secret'];

        self::assertSame([], $this->saver->accountErrors($account, self::WEBSITE_ID));
        $this->saver->saveAccount($account, self::WEBSITE_ID);

        self::assertSame('shop-tx', $this->saved[Config::XML_PATH_TRANSACTIONAL_SUBDOMAIN . '@default/0']);
        self::assertSame('api', $this->saved[Config::XML_PATH_TRANSACTIONAL_USERNAME . '@default/0']);
        self::assertSame('enc:secret', $this->saved[Config::XML_PATH_TRANSACTIONAL_PASSWORD . '@default/0']);
        self::assertNotContains('secret', $this->saved, 'The password is never stored in plain text');
        // The website follows the account saved for all websites.
        self::assertContains(Config::XML_PATH_TRANSACTIONAL_PASSWORD . '@websites/3', $this->deleted);
        self::assertContains(Config::XML_PATH_TRANSACTIONAL_WEBSITE_ACCOUNT . '@websites/3', $this->deleted);
        self::assertTrue($this->saver->isAccountAccepted());
        self::assertSame('1', $this->saved[Config::XML_PATH_TRANSACTIONAL_ENABLED . '@websites/3']);
    }

    /**
     * PRO-4065: "Send transactional emails" off (the WooCommerce plugin's
     * master switch) is saved for the website; the hidden account is
     * neither checked nor changed.
     */
    public function testWithTheSwitchOffTheAccountStaysAsItIs(): void
    {
        $this->client->expects(self::never())->method('validateCredentials');
        $account = ['enabled' => false, 'subdomain' => 'half-typed', 'username' => '', 'password' => ''];

        self::assertSame([], $this->saver->accountErrors($account, self::WEBSITE_ID));
        $this->saver->saveAccount($account, self::WEBSITE_ID);

        self::assertSame([Config::XML_PATH_TRANSACTIONAL_ENABLED . '@websites/3' => '0'], $this->saved);
        self::assertSame([], $this->deleted);
        self::assertFalse($this->saver->isAccountAccepted());
    }

    public function testAWebsiteOfAMultiWebsiteInstallMayUseItsOwnAccount(): void
    {
        $this->websiteContext->method('hasMultipleWebsites')->willReturn(true);

        $this->saver->saveAccount(
            ['enabled' => true, 'subdomain' => 'ee-tx', 'username' => 'api', 'password' => 'secret', 'website_account' => true],
            self::WEBSITE_ID
        );

        self::assertSame('ee-tx', $this->saved[Config::XML_PATH_TRANSACTIONAL_SUBDOMAIN . '@websites/3']);
        self::assertSame('enc:secret', $this->saved[Config::XML_PATH_TRANSACTIONAL_PASSWORD . '@websites/3']);
        self::assertSame('1', $this->saved[Config::XML_PATH_TRANSACTIONAL_WEBSITE_ACCOUNT . '@websites/3']);
        self::assertArrayNotHasKey(Config::XML_PATH_TRANSACTIONAL_SUBDOMAIN . '@default/0', $this->saved);
        self::assertSame([], $this->deleted, 'The account saved for all websites stays');
    }

    public function testASingleWebsiteInstallAlwaysSavesForAllWebsites(): void
    {
        $this->websiteContext->method('hasMultipleWebsites')->willReturn(false);

        $this->saver->saveAccount(
            ['enabled' => true, 'subdomain' => 'shop-tx', 'username' => 'api', 'password' => 'secret', 'website_account' => true],
            self::WEBSITE_ID
        );

        self::assertArrayHasKey(Config::XML_PATH_TRANSACTIONAL_SUBDOMAIN . '@default/0', $this->saved);
        self::assertArrayNotHasKey(Config::XML_PATH_TRANSACTIONAL_WEBSITE_ACCOUNT . '@websites/3', $this->saved);
    }

    public function testAnEmptyPasswordKeepsTheSavedOneOfTheSameAccount(): void
    {
        $this->savedAccount('shop-tx', 'api', 'stored');
        $account = ['enabled' => true, 'subdomain' => 'SHOP-TX', 'username' => 'api', 'password' => '******'];

        self::assertSame([], $this->saver->accountErrors($account, self::WEBSITE_ID));
        $this->saver->saveAccount($account, self::WEBSITE_ID);

        self::assertSame('enc:stored', $this->saved[Config::XML_PATH_TRANSACTIONAL_PASSWORD . '@default/0']);
    }

    /**
     * A website with its own account that goes back to the account saved
     * for all websites: the form shows that one, and an empty password
     * keeps its password — not the website's own.
     */
    public function testTheSharedAccountKeepsItsOwnPassword(): void
    {
        $this->websiteContext->method('hasMultipleWebsites')->willReturn(true);
        $this->config->method('getTransactionalSubdomain')->willReturnMap([[3, 'ee-tx'], [null, 'shop-tx']]);
        $this->config->method('getTransactionalUsername')->willReturnMap([[3, 'api-ee'], [null, 'api']]);
        $this->config->method('getTransactionalPassword')->willReturnMap([[3, 'ee-secret'], [null, 'secret']]);
        $shared = [
            'enabled' => true,
            'subdomain' => 'shop-tx',
            'username' => 'api',
            'password' => '',
            'website_account' => false,
        ];

        self::assertSame([], $this->saver->accountErrors($shared, self::WEBSITE_ID));
        $this->saver->saveAccount($shared, self::WEBSITE_ID);

        self::assertSame('enc:secret', $this->saved[Config::XML_PATH_TRANSACTIONAL_PASSWORD . '@default/0']);
        self::assertContains(Config::XML_PATH_TRANSACTIONAL_SUBDOMAIN . '@websites/3', $this->deleted);
        // The website's own account, unchanged, keeps the website's password.
        self::assertSame([], $this->saver->accountErrors(['enabled' => true, 'website_account' => true] + [
            'subdomain' => 'ee-tx',
            'username' => 'api-ee',
            'password' => '',
        ], self::WEBSITE_ID));
    }

    public function testAnotherAccountNeedsItsPassword(): void
    {
        $this->savedAccount('shop-tx', 'api', 'stored');

        self::assertSame(
            [[
                'field' => 'transactional.password',
                'message' => 'The subdomain or username changed — enter the password of this account.',
            ]],
            $this->saver->accountErrors(
                ['enabled' => true, 'subdomain' => 'other', 'username' => 'api', 'password' => ''],
                3
            )
        );
    }

    public function testANewAccountNeedsAPassword(): void
    {
        $this->savedAccount('shop-tx', 'api', '');

        self::assertSame(
            ['transactional.password'],
            array_column(
                $this->saver->accountErrors(
                    ['enabled' => true, 'subdomain' => 'shop-tx', 'username' => 'api', 'password' => ''],
                    3
                ),
                'field'
            )
        );
    }

    public function testTheSubdomainAndUsernameAreRequiredAndTheSubdomainMustBePlain(): void
    {
        self::assertSame(
            ['transactional.username'],
            array_column(
                $this->saver->accountErrors(['enabled' => true, 'subdomain' => 'shop-tx', 'password' => 'x'], 3),
                'field'
            )
        );
        self::assertSame(
            ['transactional.subdomain'],
            array_column(
                $this->saver->accountErrors(
                    ['enabled' => true, 'subdomain' => 'shop.tx/', 'username' => 'api', 'password' => 'x'],
                    3
                ),
                'field'
            )
        );
    }

    public function testEmptyFieldsRemoveTheAccount(): void
    {
        $account = ['enabled' => true, 'subdomain' => '', 'username' => '', 'password' => ''];

        self::assertSame([], $this->saver->accountErrors($account, self::WEBSITE_ID));
        $this->saver->saveAccount($account, self::WEBSITE_ID);

        self::assertSame(['1'], array_values($this->saved), 'Only the switch is saved');
        self::assertContains(Config::XML_PATH_TRANSACTIONAL_SUBDOMAIN . '@default/0', $this->deleted);
        self::assertContains(Config::XML_PATH_TRANSACTIONAL_PASSWORD . '@default/0', $this->deleted);
        self::assertContains(Config::XML_PATH_TRANSACTIONAL_SUBDOMAIN . '@websites/3', $this->deleted);
        self::assertFalse($this->saver->isAccountAccepted());
    }

    public function testARefusedAccountIsSavedButNotAccepted(): void
    {
        $this->client->method('validateCredentials')->willThrowException(new AuthenticationException());

        $this->saver->saveAccount(
            ['enabled' => true, 'subdomain' => 'shop-tx', 'username' => 'api', 'password' => 'bad'],
            3
        );

        self::assertArrayHasKey(Config::XML_PATH_TRANSACTIONAL_PASSWORD . '@default/0', $this->saved);
        self::assertFalse($this->saver->isAccountAccepted());
    }

    public function testTheTriggersAreSavedForTheWebsite(): void
    {
        $mappings = [['trigger_type' => 'order_confirmation', 'language' => 'default', 'workflow_id' => 12]];
        $this->mappingSaver->expects(self::once())->method('saveTransactional')
            ->with($mappings, self::WEBSITE_ID)->willReturn([]);

        $errors = $this->saver->saveTriggers([
            'order_confirmation_enabled' => true,
            'shipment_confirmation_enabled' => false,
            'personal_data_enabled' => true,
            'mappings' => $mappings,
        ], self::WEBSITE_ID);

        self::assertSame([], $errors);
        self::assertSame('1', $this->saved[Config::XML_PATH_TRANSACTIONAL_PERSONAL_DATA . '@websites/3']);
        self::assertSame('1', $this->saved[Config::XML_PATH_ORDER_CONFIRMATION_ENABLED . '@websites/3']);
        self::assertSame('0', $this->saved[Config::XML_PATH_SHIPMENT_CONFIRMATION_ENABLED . '@websites/3']);
    }

    private function savedAccount(string $subdomain, string $username, string $password): void
    {
        $this->config->method('getTransactionalSubdomain')->willReturn($subdomain);
        $this->config->method('getTransactionalUsername')->willReturn($username);
        $this->config->method('getTransactionalPassword')->willReturn($password);
    }
}
