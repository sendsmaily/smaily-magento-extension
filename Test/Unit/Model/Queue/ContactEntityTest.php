<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Queue;

use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Privacy\AddressKey;
use Smaily\Connect\Model\Queue\ContactEntity;

/**
 * PRO-3767: an address that fits the 64-character entity column is the
 * entity; a longer one is its keyed hash, of the address as the opt-out
 * record keys it (trimmed, lower case), never a cut address.
 */
class ContactEntityTest extends TestCase
{
    public function testAnAddressThatFitsIsTheEntity(): void
    {
        $addressKey = $this->createMock(AddressKey::class);
        $addressKey->expects(self::never())->method('of');
        $entity = new ContactEntity($addressKey);

        $fits = str_repeat('a', 64 - strlen('@example.invalid')) . '@example.invalid';
        self::assertSame('U1@example.invalid', $entity->of('U1@example.invalid'));
        self::assertSame($fits, $entity->of($fits));
    }

    public function testALongerAddressIsItsKeyedHash(): void
    {
        $long = 'U1-' . str_repeat('a', 70) . '@example.invalid';
        $addressKey = $this->addressKey();

        $entity = (new ContactEntity($addressKey))->of(' ' . $long);

        self::assertSame($addressKey->of(strtolower($long)), $entity);
        self::assertTrue(ContactEntity::isHash($entity));
    }

    /**
     * The GDPR erasure and the consent replay match a contact's rows by
     * every form the entity may take: the keyed hash and the address.
     */
    public function testAContactsRowsAreMatchedByTheHashAndTheAddress(): void
    {
        $addressKey = $this->addressKey();
        $entity = new ContactEntity($addressKey);

        self::assertSame(
            [$addressKey->of('u1@example.invalid'), 'u1@example.invalid'],
            $entity->forms('u1@example.invalid')
        );
        self::assertSame(['k', 'u1@example.invalid'], $entity->forms('u1@example.invalid', 'k'));
    }

    public function testOnlyA64HexEntityIsAHash(): void
    {
        self::assertTrue(ContactEntity::isHash(str_repeat('0f', 32)));
        self::assertFalse(ContactEntity::isHash(str_repeat('0F', 32)));
        self::assertFalse(ContactEntity::isHash(str_repeat('f', 63)));
        self::assertFalse(ContactEntity::isHash('u1@example.invalid'));
    }

    private function addressKey(): AddressKey
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturn('unit-test-crypt-key');

        return new AddressKey($deploymentConfig);
    }
}
