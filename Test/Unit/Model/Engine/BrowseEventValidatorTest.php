<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\BrowseEventValidator;

class BrowseEventValidatorTest extends TestCase
{
    private const UUID = '9b2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c';

    private BrowseEventValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new BrowseEventValidator();
    }

    public function testValidProductViewPassesWithServerStampedFields(): void
    {
        $clean = $this->validator->sanitize([
            'event_id' => strtoupper(self::UUID),
            'session_id' => 'sess-123',
            'event_type' => 'product_view',
            'sku' => 'ABC-1',
            'smaily_visitor_token' => 'vt_8f3k2a',
            'source' => 'spoofed-source',
            'event_ts' => '1999-01-01T00:00:00Z',
            'unknown_key' => 'dropped',
        ]);

        self::assertNotNull($clean);
        self::assertSame(self::UUID, $clean['event_id']);
        self::assertSame('plugin_magento', $clean['source']);
        self::assertNotSame('1999-01-01T00:00:00Z', $clean['event_ts']);
        self::assertSame('ABC-1', $clean['sku']);
        self::assertSame('vt_8f3k2a', $clean['smaily_visitor_token']);
        self::assertArrayNotHasKey('unknown_key', $clean);
    }

    /**
     * PRO-1762 (contract v1.7.0 §6): the engine no longer persists or
     * consults the browse-event rec id/context hints, and a malformed
     * smaily_rec_id still fails that event's validation engine-side — so
     * neither is forwarded from the anonymous beacon any more.
     */
    public function testDeprecatedRecIdAndContextHintsAreNotForwarded(): void
    {
        $clean = $this->validator->sanitize([
            'event_id' => self::UUID,
            'session_id' => 'sess-123',
            'event_type' => 'product_view',
            'sku' => 'ABC-1',
            'smaily_rec_id' => 'rec_abc123',
            'smaily_ctx' => 'ctx-9',
        ]);

        self::assertNotNull($clean);
        self::assertArrayNotHasKey('smaily_rec_id', $clean);
        self::assertArrayNotHasKey('smaily_ctx', $clean);
        self::assertSame('ABC-1', $clean['sku'], 'the rest of the event still passes');
    }

    public function testInvalidUuidRejected(): void
    {
        self::assertNull($this->validator->sanitize([
            'event_id' => 'not-a-uuid',
            'session_id' => 's',
            'event_type' => 'product_view',
        ]));
    }

    public function testUnknownEventTypeRejected(): void
    {
        self::assertNull($this->validator->sanitize([
            'event_id' => self::UUID,
            'session_id' => 's',
            'event_type' => 'page_view',
        ]));
    }

    public function testMissingSessionRejected(): void
    {
        self::assertNull($this->validator->sanitize([
            'event_id' => self::UUID,
            'event_type' => 'search',
        ]));
    }

    /**
     * PRO-3575: the anonymous beacon asserts no identity. Neither the email
     * nor the platform customer id is forwarded; identity reaches the engine
     * only from server-side state (the engine-issued visitor token and the
     * login identity merge).
     */
    public function testClientAssertedIdentityIsRejectedAndDwellCoerced(): void
    {
        $clean = $this->validator->sanitize([
            'event_id' => self::UUID,
            'session_id' => 's1',
            'event_type' => 'search',
            'search_query' => 'kassitoit',
            'customer_email' => 'spoofed@example.com',
            'external_id' => '42',
            'dwell_seconds' => '12',
        ]);

        self::assertNotNull($clean);
        // Identity must never be client-asserted on the anonymous beacon.
        self::assertArrayNotHasKey('customer_email', $clean);
        self::assertArrayNotHasKey('external_id', $clean);
        self::assertSame(12, $clean['dwell_seconds']);
        self::assertSame('kassitoit', $clean['search_query']);
    }

    /**
     * A field posted as a JSON array or object fails validation instead of
     * raising an "Array to string conversion" warning (an error page in
     * Magento).
     */
    public function testNonScalarFieldsFailValidationWithoutAWarning(): void
    {
        set_error_handler(static function (int $level, string $message): bool {
            throw new \ErrorException($message, 0, $level);
        }, E_WARNING);
        try {
            $rejected = $this->validator->sanitize([
                'event_id' => [self::UUID],
                'session_id' => 'sess-123',
                'event_type' => 'product_view',
            ]);
            $clean = $this->validator->sanitize([
                'event_id' => self::UUID,
                'session_id' => 'sess-123',
                'event_type' => 'product_view',
                'sku' => ['ABC-1'],
                'search_query' => ['q' => 'kassitoit'],
            ]);
        } finally {
            restore_error_handler();
        }

        self::assertNull($rejected);
        self::assertNotNull($clean);
        self::assertArrayNotHasKey('sku', $clean);
        self::assertArrayNotHasKey('search_query', $clean);
    }
}
