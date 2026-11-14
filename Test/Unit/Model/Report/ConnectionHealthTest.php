<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Report;

use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Report\ConnectionHealth;

/**
 * CON-234: the installation report's health of each Smaily account and each
 * engine store — the last answer's code and the last successful write,
 * stored as codes only, written at most every five minutes when only the
 * time changes, and never in the way of the request it describes.
 */
class ConnectionHealthTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $flag = [];

    private int $writes = 0;

    private int $now = 1_762_300_000;

    public function testNothingRecordedIsOkWithoutASync(): void
    {
        self::assertSame(
            ['status' => 'ok', 'last_sync_at' => null, 'last_error_code' => null],
            $this->health()->smaily('demo')
        );
    }

    public function testAWriteIsASyncAndAReadIsNot(): void
    {
        $health = $this->health();

        $health->recordSmaily('Demo ', '', false);
        self::assertNull($health->smaily('demo')['last_sync_at']);

        $health->recordSmaily('demo', '', true);
        self::assertSame(
            ['status' => 'ok', 'last_sync_at' => $this->now, 'last_error_code' => null],
            $health->smaily('DEMO')
        );
    }

    public function testAFailureKeepsTheLastSyncAndASuccessClearsIt(): void
    {
        $health = $this->health();
        $health->recordSmaily('demo', '', true);
        $synced = $this->now;

        $this->now += 60;
        $health->recordSmaily('demo', ConnectionHealth::CREDENTIALS_REJECTED, false);
        self::assertSame(
            ['status' => 'failing', 'last_sync_at' => $synced, 'last_error_code' => 'credentials_rejected'],
            $health->smaily('demo')
        );

        $health->recordSmaily('demo', '', false);
        self::assertSame(
            ['status' => 'ok', 'last_sync_at' => $synced, 'last_error_code' => null],
            $health->smaily('demo')
        );
    }

    public function testASuccessThatChangesOnlyTheTimeIsWrittenAtMostEveryFiveMinutes(): void
    {
        $health = $this->health();
        $health->recordEngine('tenant-1', '', true);
        self::assertSame(1, $this->writes);

        $this->now += ConnectionHealth::WRITE_EVERY_SECONDS - 1;
        $health->recordEngine('tenant-1', '', true);
        $health->recordEngine('tenant-1', '', false);
        self::assertSame(1, $this->writes);

        $this->now += 1;
        $health->recordEngine('tenant-1', '', true);
        self::assertSame(2, $this->writes);
        self::assertSame($this->now, $health->engine('tenant-1')['last_sync_at']);

        $health->recordEngine('tenant-1', 'rate_limit_exceeded', false);
        self::assertSame(3, $this->writes, 'A new error code is written at once.');
    }

    public function testACodeOutsideTheContractsRuleIsStoredAsUnknown(): void
    {
        $health = $this->health();
        $health->recordEngine('tenant-1', 'Bad Code: shopper@example.com', false);

        self::assertSame('unknown_error', $health->engine('tenant-1')['last_error_code']);
        self::assertStringNotContainsString('example.com', (string)json_encode($this->flag));
    }

    public function testSmailyAccountsAndEngineStoresAreKeptApart(): void
    {
        $health = $this->health();
        $health->recordSmaily('demo', 'unreachable', false);
        $health->recordEngine('demo', '', true);
        $health->recordSmaily('', 'unreachable', false);

        self::assertSame('failing', $health->smaily('demo')['status']);
        self::assertSame('ok', $health->engine('demo')['status']);
        self::assertSame(['smaily', 'engine'], array_keys($this->flag));
        self::assertSame(['demo'], array_keys($this->flag['smaily']));
    }

    public function testOnlyTheNewestEntriesAreKept(): void
    {
        $health = $this->health();
        for ($i = 0; $i <= ConnectionHealth::MAX_ENTRIES; $i++) {
            $health->recordSmaily('account' . $i, 'unreachable', false);
        }

        self::assertCount(ConnectionHealth::MAX_ENTRIES, $this->flag['smaily']);
        self::assertSame('ok', $health->smaily('account0')['status'], 'The oldest entry went.');
        self::assertSame('failing', $health->smaily('account' . ConnectionHealth::MAX_ENTRIES)['status']);
    }

    public function testAFailedWriteNeverReachesTheRequest(): void
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willThrowException(new \RuntimeException('database gone'));
        $health = new ConnectionHealth($flagManager, $this->clock());

        $health->recordSmaily('demo', '', true);

        self::assertSame('ok', $health->smaily('demo')['status']);
    }

    private function health(): ConnectionHealth
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturnCallback(fn (): array => $this->flag);
        $flagManager->method('saveFlag')->willReturnCallback(function (string $code, array $data): bool {
            self::assertSame(ConnectionHealth::FLAG_CODE, $code);
            $this->flag = $data;
            $this->writes++;

            return true;
        });

        return new ConnectionHealth($flagManager, $this->clock());
    }

    private function clock(): DateTime
    {
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);

        return $dateTime;
    }
}
