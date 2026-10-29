<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Console\Command\BackfillStatusCommand;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\ResourceModel\Backfill\Job\Collection;
use Smaily\Connect\Model\ResourceModel\Backfill\Job\CollectionFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * CON-215: `smaily:backfill:status` shows a job's stored error as the
 * admin's import card does since CON-233 — where the error happened or one
 * of the imports' own reasons, never an error's text an earlier version
 * stored — and a Campaign Intelligence import's failed_count as the rows it
 * skipped because Magento could not read them (CON-227).
 */
class BackfillStatusCommandTest extends TestCase
{
    /**
     * @dataProvider storedReasons
     */
    public function testAJobShowsOnlyWhereTheErrorHappened(string $stored, string $shown): void
    {
        $display = $this->display([$this->job(Job::TARGET_ENGINE, 0, $stored)]);

        if ($shown !== '') {
            self::assertStringContainsString($shown, $display);
        }
        self::assertStringNotContainsString('Control character', $display);
        self::assertStringNotContainsString('Order 17', $display);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function storedReasons(): array
    {
        $location = 'Magento\Framework\Exception\NoSuchEntityException at StoreRepository.php:110';

        return [
            'where the error happened' => [$location, $location],
            'a website without stores' => ['Website 3 has no stores', 'Website 3 has no stores'],
            'a website whose contact sync was off' => [
                Job::REASON_CONTACT_SYNC_INACTIVE,
                Job::REASON_CONTACT_SYNC_INACTIVE,
            ],
            'an earlier version\'s error text' => [
                'Unable to unserialize value. Error: Control character error, possibly incorrectly encoded',
                '',
            ],
            'an earlier version\'s text that ends like a location' => ['Order 17 failed at Json.php:42 for x', ''],
        ];
    }

    public function testAnEngineImportShowsItsUnreadableRowsAsSkipped(): void
    {
        $display = $this->display([
            $this->job(Job::TARGET_ENGINE, 4, ''),
            $this->job(Job::TARGET_SMAILY, 3, ''),
        ]);

        self::assertStringContainsString('Skipped (unreadable)', $display);
        $rows = array_values(preg_grep('/^\|\s+\d+\s+\|/', explode("\n", $display)) ?: []);
        self::assertCount(2, $rows);
        $cells = array_map(
            static fn (string $row): array => array_map('trim', explode('|', trim($row, '| '))),
            $rows
        );
        self::assertSame(['0', '4'], [$cells[0][6], $cells[0][7]], 'Engine: failed 0, skipped 4');
        self::assertSame(['3', '0'], [$cells[1][6], $cells[1][7]], 'Smaily: failed 3, skipped 0');
    }

    /**
     * @param Job[] $jobs
     */
    private function display(array $jobs): string
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getItems')->willReturn($jobs);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $tester = new CommandTester(new BackfillStatusCommand($collectionFactory));
        $tester->execute([]);

        return $tester->getDisplay();
    }

    private function job(string $target, int $failed, string $error): Job
    {
        $job = $this->createMock(Job::class);
        $job->method('getId')->willReturn(7);
        $job->method('getJobType')->willReturn($target === Job::TARGET_ENGINE ? Job::TYPE_ORDERS : Job::TYPE_CONTACTS);
        $job->method('getTarget')->willReturn($target);
        $job->method('getWebsiteId')->willReturn(1);
        $job->method('getStatus')->willReturn(Job::STATUS_FAILED);
        $job->method('getProcessedCount')->willReturn(10);
        $job->method('getFailedCount')->willReturn($failed);
        $job->method('getData')->willReturnMap([
            ['total_count', null, 20],
            ['error_message', null, $error],
        ]);

        return $job;
    }
}
