<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Privacy;

use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Privacy\AddressKey;

/**
 * The keyed hash of an address is stored (the opt-out record, consent and
 * long contact queue entities) and matched later, so it must never change:
 * the values below were computed with the code before AddressKey existed
 * (ProfilingOptOuts::addressKey()/keys(), Queue\ContactEntity::of()).
 */
class AddressKeyTest extends TestCase
{
    public function testTheKeyIsByteIdenticalToTheOneStoredBefore(): void
    {
        $keys = $this->addressKey('pin-test-crypt-key')->keys('u1@example.invalid');

        self::assertSame([
            '855ab551c92f0d46f3fba5c41096e7621b8bf3e73bc93d8620207cabcabe92f7',
            '831e6d3dc2297671b41f1680583f502d95457ceb',
        ], $keys);
        self::assertSame($keys[0], $this->addressKey('pin-test-crypt-key')->of('u1@example.invalid'));
    }

    public function testAfterAKeyRotationTheNewestKeyComesFirst(): void
    {
        self::assertSame([
            '470f8ceeec827ebf7345d7e29a35effc9ff78151a340d75626b8faf9fc0cdb80',
            '20848b35687766b0e91de63feb61729107021d16b1533bd6644e676af73a6139',
            '831e6d3dc2297671b41f1680583f502d95457ceb',
        ], $this->addressKey("old-pin-key\nnew-pin-key")->keys('u1@example.invalid'));
    }

    /**
     * The address is normalised here (lower case, trimmed), as the contact
     * entity normalised it before hashing a long address.
     */
    public function testTheAddressIsNormalisedBeforeItIsKeyed(): void
    {
        $long = ' U2-' . str_repeat('A', 70) . '@Example.invalid ';

        self::assertSame(
            '16e6ea7a54f61fe34427d8ec638f77616455152750c0e98319b361922db4f0fd',
            $this->addressKey('pin-test-crypt-key')->of($long)
        );
        self::assertSame(
            '86bf6d38933e70233a6ccad16cce023c1b621e3ae8f373cfb04a5ddc5156d989',
            $this->addressKey("old-pin-key\nnew-pin-key")->of($long)
        );
        self::assertSame('u2@example.invalid', AddressKey::normalise(' U2@Example.invalid '));
    }

    public function testTheCryptKeyIsReadOncePerInstance(): void
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->expects(self::once())->method('get')->willReturn('pin-test-crypt-key');
        $addressKey = new AddressKey($deploymentConfig);

        $addressKey->of('u1@example.invalid');
        $addressKey->of('u2@example.invalid');
        $addressKey->keys('u3@example.invalid');
    }

    private function addressKey(string $cryptKey): AddressKey
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturn($cryptKey);

        return new AddressKey($deploymentConfig);
    }
}
