<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Client;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Config;

/**
 * PRO-3560: "Connected" means Smaily accepted the saved credentials at the
 * last real check — not merely that three fields are filled in. Changing the
 * credentials or a later refusal by Smaily takes the status away again.
 */
class VerifiedCredentialsTest extends TestCase
{
    private mixed $flagData = null;

    /** @var array{subdomain: string, username: string, password: string} */
    private array $saved = ['subdomain' => '', 'username' => '', 'password' => ''];

    private VerifiedCredentials $verified;

    protected function setUp(): void
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->with(VerifiedCredentials::FLAG_CODE)
            ->willReturnCallback(fn () => $this->flagData);
        $flagManager->method('saveFlag')->willReturnCallback(function (string $code, $value): bool {
            self::assertSame(VerifiedCredentials::FLAG_CODE, $code);
            $this->flagData = $value;

            return true;
        });

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('hash')->willReturnCallback(static fn (string $data): string => hash('sha256', $data));

        $config = $this->createMock(Config::class);
        $config->method('getSubdomain')->willReturnCallback(fn () => $this->saved['subdomain']);
        $config->method('getUsername')->willReturnCallback(fn () => $this->saved['username']);
        $config->method('getPassword')->willReturnCallback(fn () => $this->saved['password']);
        $config->method('isConnected')->willReturnCallback(
            fn () => !in_array('', $this->saved, true)
        );

        $this->verified = new VerifiedCredentials($flagManager, $encryptor, $config);
    }

    public function testMissingCredentialsAreNotConnected(): void
    {
        $this->verified->accept('demo', 'user', 'secret');

        self::assertFalse($this->verified->isVerified(1));
    }

    public function testSavedCredentialsThatWereNeverAcceptedAreNotConnected(): void
    {
        $this->save('demo', 'user', 'secret');

        self::assertFalse($this->verified->isVerified(1));
    }

    public function testSavedCredentialsSmailyAcceptedAreConnected(): void
    {
        $this->save('demo', 'user', 'secret');
        $this->verified->accept('demo', 'user', 'secret');

        self::assertTrue($this->verified->isVerified(1));
    }

    public function testChangedCredentialsAreNotConnectedUntilChecked(): void
    {
        $this->verified->accept('demo', 'user', 'secret');
        $this->save('demo', 'user', 'another-secret');

        self::assertFalse($this->verified->isVerified(1));
    }

    public function testALaterRefusalTakesTheConnectionAway(): void
    {
        $this->save('demo', 'user', 'secret');
        $this->verified->accept('demo', 'user', 'secret');
        $this->verified->refuse('demo', 'user', 'secret');

        self::assertFalse($this->verified->isVerified(1));
    }

    public function testARefusalOfOtherCredentialsLeavesTheSavedOnesConnected(): void
    {
        $this->save('demo', 'user', 'secret');
        $this->verified->accept('demo', 'user', 'secret');
        $this->verified->refuse('demo', 'user', 'typo');

        self::assertTrue($this->verified->isVerified(1));
    }

    public function testThePasswordIsNeverStored(): void
    {
        $this->verified->accept('demo', 'user', 'secret');

        self::assertIsArray($this->flagData);
        self::assertStringNotContainsString('secret', (string)json_encode($this->flagData));
    }

    public function testOnlyTheLatestAcceptedCredentialsAreKept(): void
    {
        $this->save('demo', 'user', 'secret');
        $this->verified->accept('demo', 'user', 'secret');
        for ($i = 0; $i < VerifiedCredentials::MAX_REMEMBERED; $i++) {
            $this->verified->accept('demo', 'user', 'tested-' . $i);
        }

        self::assertCount(VerifiedCredentials::MAX_REMEMBERED, $this->flagData);
        self::assertFalse($this->verified->isVerified(1));
    }

    private function save(string $subdomain, string $username, string $password): void
    {
        $this->saved = ['subdomain' => $subdomain, 'username' => $username, 'password' => $password];
    }
}
