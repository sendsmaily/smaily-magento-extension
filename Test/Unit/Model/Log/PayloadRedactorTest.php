<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Log;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Log\PayloadRedactor;

class PayloadRedactorTest extends TestCase
{
    private PayloadRedactor $redactor;

    protected function setUp(): void
    {
        $this->redactor = new PayloadRedactor();
    }

    public function testEmptyAndNullInputYieldEmptyString(): void
    {
        self::assertSame('', $this->redactor->redact(null));
        self::assertSame('', $this->redactor->redact(''));
        self::assertSame('', $this->redactor->redact('   '));
    }

    /**
     * The admin Log shows contact data in full, as the WooCommerce plugin's
     * log does; only secrets are hidden.
     */
    public function testShowsContactDataInJsonValuesInFull(): void
    {
        $result = $this->redactor->redact(json_encode([
            'email' => 'jane.doe@example.com',
            'nested' => ['contact' => 'john%40example.co.uk'],
        ]));

        self::assertStringContainsString('jane.doe@example.com', $result);
        self::assertStringContainsString('john%40example.co.uk', $result);
    }

    public function testRedactsSecretLookingKeysRecursively(): void
    {
        $result = $this->redactor->redact(json_encode([
            'password' => 'hunter2',
            'api_key' => 'sk_live_123',
            'apiKey' => 'sk_live_456',
            'auth' => ['token' => 'abc', 'authorization' => 'Bearer xyz'],
            'safe' => 'kept',
        ]));

        self::assertStringNotContainsString('hunter2', $result);
        self::assertStringNotContainsString('sk_live_123', $result);
        self::assertStringNotContainsString('sk_live_456', $result);
        self::assertStringNotContainsString('Bearer xyz', $result);
        self::assertStringContainsString('[redacted]', $result);
        self::assertStringContainsString('kept', $result);
    }

    public function testNonJsonInputIsShownAsItIs(): void
    {
        $result = $this->redactor->redact('HTTP 500 while syncing customer john@shop.example.org to Smaily');

        self::assertSame('HTTP 500 while syncing customer john@shop.example.org to Smaily', $result);
    }

    public function testNonSecretScalarsSurviveUntouched(): void
    {
        $result = $this->redactor->redact(json_encode([
            'quantity' => 3,
            'price' => 12.5,
            'active' => true,
            'note' => null,
        ]));

        $decoded = json_decode($result, true);
        self::assertSame(3, $decoded['quantity']);
        self::assertSame(12.5, $decoded['price']);
        self::assertTrue($decoded['active']);
        self::assertNull($decoded['note']);
    }

    public function testJsonOutputIsPrettyPrinted(): void
    {
        $result = $this->redactor->redact('{"a":{"b":1}}');

        self::assertStringContainsString("\n", $result);
        self::assertSame(['a' => ['b' => 1]], json_decode($result, true));
    }
}
