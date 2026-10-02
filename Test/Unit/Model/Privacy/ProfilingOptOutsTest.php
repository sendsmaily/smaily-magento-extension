<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Privacy;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Privacy\ProfilingOptOuts;

class ProfilingOptOutsTest extends TestCase
{
    private const CRYPT_KEY = 'unit-test-crypt-key';

    /** @var array<string, mixed> */
    private array $flags = [];

    /** @var string[] */
    private array $lockCalls = [];

    private ProfilingOptOuts $optOuts;

    protected function setUp(): void
    {
        $this->optOuts = $this->optOuts(self::CRYPT_KEY);
    }

    private function optOuts(string $cryptKey): ProfilingOptOuts
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

        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturn($cryptKey);

        return new ProfilingOptOuts($flagManager, $lockManager, $deploymentConfig);
    }

    public function testAnAddressWithoutAnOptOutHasNoMoment(): void
    {
        self::assertNull($this->optOuts->moment('person@example.com'));
    }

    public function testARecordedOptOutKeepsItsMoment(): void
    {
        $this->optOuts->record('person@example.com', 1790000000);

        self::assertSame(1790000000, $this->optOuts->moment('person@example.com'));
    }

    public function testAMirroredOptOutHasMomentZero(): void
    {
        $this->optOuts->record('person@example.com', 0);

        self::assertSame(0, $this->optOuts->moment('person@example.com'));
    }

    public function testAnOptOutMadeByUnsubscribingIsKeptAsSuch(): void
    {
        $this->optOuts->record('person@example.com', 1790000000, true);
        $this->optOuts->record('other@example.com', 1790000001);

        self::assertSame(1790000000, $this->optOuts->moment('person@example.com'));
        self::assertTrue($this->optOuts->isByUnsubscribe('person@example.com'));
        self::assertFalse($this->optOuts->isByUnsubscribe('other@example.com'));
        self::assertFalse($this->optOuts->isByUnsubscribe('nobody@example.com'));
    }

    public function testAProfilingOptOutReplacesAnUnsubscribeOne(): void
    {
        $this->optOuts->record('person@example.com', 1790000000, true);
        $this->optOuts->record('person@example.com', 1790000001);

        self::assertSame(1790000001, $this->optOuts->moment('person@example.com'));
        self::assertFalse($this->optOuts->isByUnsubscribe('person@example.com'));
    }

    public function testAnEntryKeptBeforeOriginsWereRecordedCountsAsAProfilingOptOut(): void
    {
        $this->flags[ProfilingOptOuts::FLAG_CODE] = [sha1('person@example.com') => 1790000000];

        self::assertSame(1790000000, $this->optOuts->moment('person@example.com'));
        self::assertFalse($this->optOuts->isByUnsubscribe('person@example.com'));
    }

    public function testTheRecordIsKeyedWithTheStoresSecretNotAPlainHash(): void
    {
        $this->optOuts->record('person@example.com', 1790000000);

        $keys = array_keys($this->flags[ProfilingOptOuts::FLAG_CODE]);
        self::assertCount(1, $keys);
        self::assertNotSame(sha1('person@example.com'), $keys[0]);
        self::assertNotSame(hash('sha256', 'person@example.com'), $keys[0]);
        self::assertNull(
            $this->optOuts('another-store-key')->moment('person@example.com'),
            'Without the store\'s secret the record does not reveal the address'
        );
    }

    /**
     * PRO-3575: the address's key is the one its entry is written under —
     * keyed with the store's secret — and ProfilingConsent keeps its cache
     * entries under it too.
     */
    public function testTheAddressKeyIsTheKeyedFormTheRecordIsWrittenUnder(): void
    {
        $this->optOuts->record('person@example.com', 1790000000);

        $key = $this->optOuts->addressKey('person@example.com');
        self::assertSame([$key], array_keys($this->flags[ProfilingOptOuts::FLAG_CODE]));
        self::assertNotSame(sha1('person@example.com'), $key);
        self::assertNotSame($key, $this->optOuts('another-store-key')->addressKey('person@example.com'));
    }

    public function testAWriteMovesAnEntryKeptUnderThePlainHashToTheKeyedForm(): void
    {
        $this->flags[ProfilingOptOuts::FLAG_CODE] = [
            sha1('person@example.com') => 1790000000,
            sha1('other@example.com') => 1790000001,
        ];

        $this->optOuts->record('person@example.com', 1790000002, true);

        $stored = $this->flags[ProfilingOptOuts::FLAG_CODE];
        self::assertArrayNotHasKey(sha1('person@example.com'), $stored);
        self::assertCount(2, $stored);
        self::assertSame(1790000002, $this->optOuts->moment('person@example.com'));
        self::assertTrue($this->optOuts->isByUnsubscribe('person@example.com'));
        self::assertSame(1790000001, $this->optOuts->moment('other@example.com'), 'Untouched entries stay readable');
    }

    public function testForgettingRemovesAnEntryKeptUnderThePlainHash(): void
    {
        $this->flags[ProfilingOptOuts::FLAG_CODE] = [sha1('person@example.com') => 1790000000];

        $this->optOuts->forget('person@example.com');

        self::assertNull($this->optOuts->moment('person@example.com'));
        self::assertSame([], $this->flags[ProfilingOptOuts::FLAG_CODE]);
    }

    public function testAnOptOutSurvivesACryptKeyRotation(): void
    {
        $this->optOuts('old-key')->record('person@example.com', 1790000000);
        $rotated = $this->optOuts("old-key\nnew-key");

        self::assertSame(1790000000, $rotated->moment('person@example.com'));

        $rotated->record('person@example.com', 1790000001);
        self::assertCount(1, $this->flags[ProfilingOptOuts::FLAG_CODE], 'The entry moves to the newest key');
        self::assertSame(1790000001, $this->optOuts('new-key')->moment('person@example.com'));
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
