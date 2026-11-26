<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\PrivacyRequestBudget;

/**
 * CON-432: the engine's limit on privacy requests, 10 in 60 seconds per
 * engine store (contract v1.37.1, Rate limiting).
 */
class PrivacyRequestBudgetTest extends TestCase
{
    private const STORE = '6d1f0c3e-2a7b-4c59-8e14-b9a0f3d5c217';
    private const OTHER_STORE = '0b4e7a91-5c3d-4f28-9a61-d2e8c4b7f053';

    /** @var array<string, string> */
    private array $cache = [];

    private int $now = 1800000000;

    public function testTheEleventhRequestInAMinuteWaitsForTheOldestToLeaveTheWindow(): void
    {
        $budget = $this->budget();
        for ($i = 0; $i < PrivacyRequestBudget::LIMIT; $i++) {
            self::assertSame(0, $budget->waitSeconds(self::STORE));
            $budget->spend(self::STORE);
            $this->now += 3;
        }

        // The first request went 30 seconds ago.
        self::assertSame(30, $budget->waitSeconds(self::STORE));
        self::assertSame(0, $budget->waitSeconds(self::OTHER_STORE), 'Each engine store has a limit of its own');

        $this->now += 30;
        self::assertSame(0, $budget->waitSeconds(self::STORE));
        $budget->spend(self::STORE);
        self::assertSame(3, $budget->waitSeconds(self::STORE), 'The second request leaves the window next');
    }

    public function testA429PausesTheStoreForAsLongAsTheEngineAsks(): void
    {
        $budget = $this->budget();
        self::assertSame(300, $budget->pause(self::STORE, 300));
        self::assertSame(300, $budget->waitSeconds(self::STORE));

        // A shorter answer never shortens the pause.
        $budget->pause(self::STORE, 5);
        $this->now += 100;
        self::assertSame(200, $budget->waitSeconds(self::STORE));

        $this->now += 200;
        self::assertSame(0, $budget->waitSeconds(self::STORE));
    }

    public function testAPauseIsAtLeastASecondAndAtMostTheRetryLaddersCeiling(): void
    {
        $budget = $this->budget();
        self::assertSame(1, $budget->pause(self::STORE, 0));
        self::assertSame(
            PrivacyRequestBudget::MAX_PAUSE_SECONDS,
            $budget->pause(self::OTHER_STORE, 10 * PrivacyRequestBudget::MAX_PAUSE_SECONDS)
        );
        self::assertSame(PrivacyRequestBudget::MAX_PAUSE_SECONDS, $budget->waitSeconds(self::OTHER_STORE));
    }

    public function testAnEmptyOrUnreadableCacheAllowsARequest(): void
    {
        $budget = $this->budget();
        self::assertSame(0, $budget->waitSeconds(self::STORE));

        foreach (array_keys($this->cache) as $key) {
            $this->cache[$key] = 'not json';
        }
        $budget->spend(self::STORE);
        $this->cache = array_map(static fn (): string => '{"sent":"x"}', $this->cache);
        self::assertSame(0, $budget->waitSeconds(self::STORE));
    }

    private function budget(): PrivacyRequestBudget
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key): string|false => $this->cache[$key] ?? false);
        $cache->method('save')->willReturnCallback(function (string $data, string $key): bool {
            $this->cache[$key] = $data;

            return true;
        });
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);

        return new PrivacyRequestBudget($cache, $dateTime);
    }
}
