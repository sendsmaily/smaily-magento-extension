<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\ContactSync;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\ContactSync\Mode;

class ModeTest extends TestCase
{
    private Config&MockObject $config;
    private Mode $mode;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->mode = new Mode($this->config);
    }

    public function testUnknownStoredModeFallsBackToConsent(): void
    {
        $this->config->method('getSyncMode')->willReturn('bogus_mode');

        self::assertSame(SyncMode::MODE_CONSENT, $this->mode->mode(1));
        self::assertTrue($this->mode->reconciles(1));
    }

    public function testConsentPolicy(): void
    {
        $this->config->method('getSyncMode')->willReturn(SyncMode::MODE_CONSENT);
        $this->config->method('includeGuests')->willReturn(false);

        self::assertTrue($this->mode->syncsAccounts(1));
        self::assertTrue($this->mode->requiresOptin(1));
        self::assertTrue($this->mode->reconciles(1));
        self::assertFalse($this->mode->includeGuests(1));
    }

    public function testLegitimateInterestPolicy(): void
    {
        $this->config->method('getSyncMode')->willReturn(SyncMode::MODE_LEGITIMATE_INTEREST);
        $this->config->method('includeGuests')->willReturn(true);

        self::assertTrue($this->mode->syncsAccounts(1));
        self::assertFalse($this->mode->requiresOptin(1));
        self::assertFalse($this->mode->reconciles(1));
        self::assertTrue($this->mode->includeGuests(1));
    }

    public function testCheckoutOptinPolicy(): void
    {
        $this->config->method('getSyncMode')->willReturn(SyncMode::MODE_CHECKOUT_OPTIN);
        $this->config->method('includeGuests')->willReturn(false);

        self::assertFalse($this->mode->syncsAccounts(1));
        self::assertTrue($this->mode->requiresOptin(1));
        self::assertFalse($this->mode->reconciles(1));
        // Guests are intrinsic to checkout-only mode regardless of the toggle.
        self::assertTrue($this->mode->includeGuests(1));
    }
}
