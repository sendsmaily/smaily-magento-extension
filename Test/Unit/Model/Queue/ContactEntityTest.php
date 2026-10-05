<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Queue;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Privacy\ProfilingOptOuts;
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
        $optOuts = $this->createMock(ProfilingOptOuts::class);
        $optOuts->expects(self::never())->method('addressKey');
        $entity = new ContactEntity($optOuts);

        $fits = str_repeat('a', 64 - strlen('@example.invalid')) . '@example.invalid';
        self::assertSame('U1@example.invalid', $entity->of('U1@example.invalid'));
        self::assertSame($fits, $entity->of($fits));
    }

    public function testALongerAddressIsItsKeyedHash(): void
    {
        $long = 'U1-' . str_repeat('a', 70) . '@example.invalid';
        $optOuts = $this->createMock(ProfilingOptOuts::class);
        $optOuts->expects(self::once())->method('addressKey')
            ->with(strtolower($long))
            ->willReturn(str_repeat('f', 64));

        self::assertSame(str_repeat('f', 64), (new ContactEntity($optOuts))->of(' ' . $long));
    }
}
