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
    /** @var array<string, mixed> flag data by flag code */
    private array $flags = [];

    /** @var array{subdomain: string, username: string, password: string} */
    private array $saved = ['subdomain' => '', 'username' => '', 'password' => ''];

    /**
     * The account saved for the website: the same as the store view's,
     * unless a test gives the store view another one.
     *
     * @var array{subdomain: string, username: string, password: string}
     */
    private array $websiteSaved = ['subdomain' => '', 'username' => '', 'password' => ''];

    private VerifiedCredentials $verified;

    protected function setUp(): void
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')
            ->willReturnCallback(fn (string $code) => $this->flags[$code] ?? null);
        $flagManager->method('saveFlag')->willReturnCallback(function (string $code, $value): bool {
            self::assertContains(
                $code,
                [VerifiedCredentials::FLAG_CODE, VerifiedCredentials::PLAN_BLOCKED_FLAG_CODE]
            );
            $this->flags[$code] = $value;

            return true;
        });

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('hash')->willReturnCallback(static fn (string $data): string => hash('sha256', $data));

        $config = $this->createMock(Config::class);
        $config->method('getSubdomain')->willReturnCallback(fn () => $this->saved['subdomain']);
        $config->method('getUsername')->willReturnCallback(fn () => $this->saved['username']);
        $config->method('getPassword')->willReturnCallback(fn () => $this->saved['password']);
        $config->method('getWebsiteSubdomain')->willReturnCallback(fn () => $this->websiteSaved['subdomain']);
        $config->method('getWebsiteUsername')->willReturnCallback(fn () => $this->websiteSaved['username']);
        $config->method('getWebsitePassword')->willReturnCallback(fn () => $this->websiteSaved['password']);
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

    /**
     * PRO-3579: a package without API access (Smaily code 227) is answered
     * before the credentials are checked. The store is not connected — nothing
     * gets through — but the reason is the package, not the credentials.
     */
    public function testAPackageWithoutApiAccessIsNotConnectedForThatReason(): void
    {
        $this->save('demo', 'user', 'secret');
        $this->verified->accept('demo', 'user', 'secret');
        $this->verified->planBlocked('demo', 'user', 'secret');

        self::assertFalse($this->verified->isVerified(1));
        self::assertTrue($this->verified->isWebsitePlanBlocked(1));
    }

    public function testAPassedCheckClearsTheBlockedPackage(): void
    {
        $this->save('demo', 'user', 'secret');
        $this->verified->planBlocked('demo', 'user', 'secret');
        $this->verified->accept('demo', 'user', 'secret');

        self::assertTrue($this->verified->isVerified(1));
        self::assertFalse($this->verified->isWebsitePlanBlocked(1));
    }

    public function testACredentialRefusalReplacesTheBlockedPackage(): void
    {
        $this->save('demo', 'user', 'secret');
        $this->verified->planBlocked('demo', 'user', 'secret');
        $this->verified->refuse('demo', 'user', 'secret');

        self::assertFalse($this->verified->isVerified(1));
        self::assertFalse($this->verified->isWebsitePlanBlocked(1));
    }

    public function testABlockedPackageOfOtherCredentialsLeavesTheSavedOnesConnected(): void
    {
        $this->save('demo', 'user', 'secret');
        $this->verified->accept('demo', 'user', 'secret');
        $this->verified->planBlocked('other', 'user', 'secret');

        self::assertTrue($this->verified->isVerified(1));
        self::assertFalse($this->verified->isWebsitePlanBlocked(1));
    }

    public function testMissingCredentialsAreNotBlocked(): void
    {
        $this->verified->planBlocked('demo', 'user', 'secret');

        self::assertFalse($this->verified->isWebsitePlanBlocked(1));
    }

    public function testThePasswordIsNeverStored(): void
    {
        $this->verified->accept('demo', 'user', 'secret');

        $this->verified->planBlocked('demo', 'user', 'other-secret');

        self::assertCount(2, $this->flags);
        self::assertStringNotContainsString('secret', (string)json_encode($this->flags));
    }

    public function testOnlyTheLatestAcceptedCredentialsAreKept(): void
    {
        $this->save('demo', 'user', 'secret');
        $this->verified->accept('demo', 'user', 'secret');
        for ($i = 0; $i < VerifiedCredentials::MAX_REMEMBERED; $i++) {
            $this->verified->accept('demo', 'user', 'tested-' . $i);
        }

        self::assertCount(VerifiedCredentials::MAX_REMEMBERED, $this->flags[VerifiedCredentials::FLAG_CODE]);
        self::assertFalse($this->verified->isVerified(1));
    }

    /**
     * PRO-3719: the website's status is the account saved for the website
     * (with per-language accounts the default fallback account), not the
     * account one of its store views holds.
     */
    public function testTheWebsiteIsConnectedWhenSmailyAcceptedTheWebsitesAccount(): void
    {
        $this->save('en-shop', 'en-user', 'en-secret');
        $this->saved = ['subdomain' => 'et-shop', 'username' => 'et-user', 'password' => 'et-secret'];
        $this->verified->accept('en-shop', 'en-user', 'en-secret');

        self::assertTrue($this->verified->isWebsiteVerified(1));
        self::assertFalse($this->verified->isVerified(2));

        $this->verified->accept('et-shop', 'et-user', 'et-secret');
        $this->verified->refuse('en-shop', 'en-user', 'en-secret');

        self::assertFalse($this->verified->isWebsiteVerified(1));
        self::assertTrue($this->verified->isVerified(2));
    }

    /**
     * Saves the credentials for the website and its store views.
     */
    private function save(string $subdomain, string $username, string $password): void
    {
        $this->saved = ['subdomain' => $subdomain, 'username' => $username, 'password' => $password];
        $this->websiteSaved = $this->saved;
    }
}
