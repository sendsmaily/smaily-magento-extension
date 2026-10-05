<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Log;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Log\EntityLabel;
use Smaily\Connect\Model\Queue\EventType;

/**
 * PRO-3765: a profiling-consent row's Entity is the shopper's keyed hash,
 * shown short; every other Entity shows as stored.
 */
class EntityLabelTest extends TestCase
{
    public function testAConsentRowShowsTheShortFormOfItsKeyedHash(): void
    {
        $hash = hash_hmac('sha256', 'u1@example.invalid', 'unit-test-crypt-key');

        self::assertSame(
            substr($hash, 0, 12) . '…',
            EntityLabel::forDisplay(EventType::ENGINE_PROFILING_CONSENT, $hash)
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function shownAsStored(): array
    {
        $hash = hash_hmac('sha256', 'u1@example.invalid', 'unit-test-crypt-key');

        return [
            'a consent row queued with the plain address' => [
                EventType::ENGINE_PROFILING_CONSENT,
                'u1@example.invalid',
            ],
            'an erased consent row' => [EventType::ENGINE_PROFILING_CONSENT, '[erased]'],
            'a contact sync' => [EventType::CONTACT_SYNC, 'u1@example.invalid'],
            'a hash-like entity of another type' => [EventType::CONTACT_SYNC, $hash],
            'an engine row' => ['orders', '100000001'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('shownAsStored')]
    public function testAnyOtherEntityShowsAsStored(string $type, string $entityId): void
    {
        self::assertSame($entityId, EntityLabel::forDisplay($type, $entityId));
    }
}
