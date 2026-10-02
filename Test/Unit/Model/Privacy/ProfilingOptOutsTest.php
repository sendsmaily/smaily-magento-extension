<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Privacy;

use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Privacy\ProfilingOptOuts;

class ProfilingOptOutsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $flags = [];

    /** @var string[] */
    private array $lockCalls = [];

    private ProfilingOptOuts $optOuts;

    protected function setUp(): void
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturnCallback(
            fn (string $code): mixed => $this->flags[$code] ?? null
        );
        $flagManager->method('saveFlag')->willReturnCallback(
            function (string $code, mixed $value): bool {
                // The flag row stores JSON: what comes back is what JSON keeps.
                $this->flags[$code] = json_decode((string)json_encode($value), true);

                return true;
            }
        );

        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturnCallback(function (): bool {
            $this->lockCalls[] = 'lock';

            return true;
        });
        $lockManager->method('unlock')->willReturnCallback(function (): bool {
            $this->lockCalls[] = 'unlock';

            return true;
        });

        $this->optOuts = new ProfilingOptOuts($flagManager, $lockManager);
    }

    public function testAnAddressWithoutAnOptOutHasNoMoment(): void
    {
        self::assertNull($this->optOuts->moment('person@example.com'));
    }

    public function testARecordedOptOutKeepsItsMoment(): void
    {
        $this->optOuts->record('person@example.com', 1790000000);

        self::assertSame(1790000000, $this->optOuts->moment('person@example.com'));
        self::assertSame(1790000000, $this->optOuts->moment('  Person@Example.com '), 'Address is normalised');
    }

    public function testAMirroredOptOutHasMomentZero(): void
    {
        $this->optOuts->record('person@example.com', 0);

        self::assertSame(0, $this->optOuts->moment('person@example.com'));
    }

    public function testForgettingRemovesOnlyThatAddress(): void
    {
        $this->optOuts->record('person@example.com', 1790000000);
        $this->optOuts->record('other@example.com', 1790000001);

        $this->optOuts->forget('person@example.com');

        self::assertNull($this->optOuts->moment('person@example.com'));
        self::assertSame(1790000001, $this->optOuts->moment('other@example.com'));
    }

    public function testTheAddressItselfIsNeverStored(): void
    {
        $this->optOuts->record('person@example.com', 1790000000);

        self::assertStringNotContainsString(
            'person@example.com',
            (string)json_encode($this->flags[ProfilingOptOuts::FLAG_CODE])
        );
    }

    public function testEveryChangeHoldsTheLock(): void
    {
        $this->optOuts->record('person@example.com', 1790000000);
        $this->optOuts->forget('person@example.com');

        self::assertSame(['lock', 'unlock', 'lock', 'unlock'], $this->lockCalls);
    }
}
