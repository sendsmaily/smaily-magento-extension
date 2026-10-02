<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\AbandonedCart;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\AbandonedCart\RestoreTokenManager;
use Smaily\Connect\Model\AbandonedCart\StateManager;

class RestoreTokenManagerTest extends TestCase
{
    private const KEY = 'unit-test-crypt-key';
    private const ISSUED_AT = 1790000000;
    private const DAY = 86400;

    private int $now = self::ISSUED_AT;

    private ?string $mailSentAt = null;

    private RestoreTokenManager $tokens;

    protected function setUp(): void
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturn(self::KEY);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);

        $stateManager = $this->createMock(StateManager::class);
        $stateManager->method('mailSentAt')->willReturnCallback(fn (): ?string => $this->mailSentAt);

        $this->tokens = new RestoreTokenManager($deploymentConfig, $dateTime, $stateManager);
    }

    public function testALinkCarriesTheQuoteTheMomentItWasIssuedAndASignature(): void
    {
        $params = $this->tokens->linkParams(42);

        self::assertSame(42, $params['id']);
        self::assertSame(self::ISSUED_AT, $params['ts']);
        self::assertSame(RestoreTokenManager::VALID, $this->check($params));
    }

    public function testALinkStaysValidForThirtyDaysAndThenExpires(): void
    {
        $params = $this->tokens->linkParams(42);

        $this->now = self::ISSUED_AT + 30 * self::DAY;
        self::assertSame(RestoreTokenManager::VALID, $this->check($params));

        $this->now = self::ISSUED_AT + 30 * self::DAY + 1;
        self::assertSame(RestoreTokenManager::EXPIRED, $this->check($params));
    }

    public function testAChangedIssueMomentIsNotAccepted(): void
    {
        $params = $this->tokens->linkParams(42);
        $this->now = self::ISSUED_AT + 31 * self::DAY;

        $params['ts'] = self::ISSUED_AT + 30 * self::DAY;

        self::assertSame(RestoreTokenManager::INVALID, $this->check($params));
    }

    public function testATokenForAnotherQuoteIsNotAccepted(): void
    {
        $params = $this->tokens->linkParams(42);
        $params['id'] = 43;

        self::assertSame(RestoreTokenManager::INVALID, $this->check($params));
    }

    public function testAMissingOrMalformedTokenIsNotAccepted(): void
    {
        $params = $this->tokens->linkParams(42);

        self::assertSame(
            RestoreTokenManager::INVALID,
            $this->tokens->check(42, (string)$params['ts'], '')
        );
        self::assertSame(
            RestoreTokenManager::INVALID,
            $this->tokens->check(42, $params['ts'] . 'x', $params['token'])
        );
    }

    public function testALinkWithoutAnIssueMomentIsValidForThirtyDaysAfterTheReminderWasSent(): void
    {
        $legacyToken = hash_hmac('sha256', 'smaily-cart-restore|42', self::KEY);

        $this->mailSentAt = gmdate('Y-m-d H:i:s', self::ISSUED_AT - 29 * self::DAY);
        self::assertSame(RestoreTokenManager::VALID, $this->tokens->check(42, '', $legacyToken));

        $this->mailSentAt = gmdate('Y-m-d H:i:s', self::ISSUED_AT - 31 * self::DAY);
        self::assertSame(RestoreTokenManager::EXPIRED, $this->tokens->check(42, '', $legacyToken));
    }

    public function testALinkWithoutAnIssueMomentAndNoSentReminderIsExpired(): void
    {
        $legacyToken = hash_hmac('sha256', 'smaily-cart-restore|42', self::KEY);

        self::assertSame(RestoreTokenManager::EXPIRED, $this->tokens->check(42, '', $legacyToken));
    }

    public function testAForgedLinkWithoutAnIssueMomentIsNotAccepted(): void
    {
        $this->mailSentAt = gmdate('Y-m-d H:i:s', self::ISSUED_AT);

        self::assertSame(RestoreTokenManager::INVALID, $this->tokens->check(42, '', str_repeat('0', 64)));
    }

    /**
     * @param array{id: int, ts: int, token: string} $params
     */
    private function check(array $params): string
    {
        return $this->tokens->check($params['id'], (string)$params['ts'], $params['token']);
    }
}
