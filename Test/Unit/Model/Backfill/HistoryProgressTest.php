<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Backfill;

use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\HistoryImport;
use Smaily\Connect\Model\Backfill\HistoryProgress;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;

/**
 * CON-241 (contract v1.20.0 §25): the history import reports `started`,
 * `start_part_complete` and `complete` with the counts the engine accepted,
 * at each phase change and at most every five minutes in between; a call
 * that fails is logged, tried again five minutes later and never stops the
 * import.
 */
class HistoryProgressTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /** @var array<string, mixed> */
    private array $state;

    /** @var array<int, Job&MockObject> */
    private array $jobs = [];

    /** @var array<string, int> undelivered rows by "job ids[:up to id]" */
    private array $undelivered = [];

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    private ?\Throwable $engineFails = null;

    protected function setUp(): void
    {
        $this->state = [
            'import_id' => 'mag-2027-01-15-9',
            'orders_job_id' => 9,
            'start_months' => 18,
            'horizon_months' => 60,
            'order' => 'newest_first',
            'until' => '2027-01-15 08:00:00',
            'start_from' => '2025-07-15 08:00:00',
            'horizon' => '2022-01-15 08:00:00',
            'start_expected' => 400,
            'orders_expected' => 1000,
            'buyers_expected' => 650,
            'start_part_queued_to' => null,
            'start_part_done' => false,
            'reported_phase' => null,
            'reported_at' => 0,
            'failed_at' => 0,
        ];
        $this->jobs = [
            9 => $this->job(9, Job::TYPE_ORDERS, Job::STATUS_RUNNING, 1000, 120),
            7 => $this->job(7, Job::TYPE_CATALOG, Job::STATUS_COMPLETED, 300, 300),
            8 => $this->job(8, Job::TYPE_CUSTOMERS, Job::STATUS_COMPLETED, 500, 498),
        ];
    }

    public function testTheImportReportsStartedWithTheCountsTheEngineAccepted(): void
    {
        $this->progress()->report();

        self::assertCount(1, $this->calls);
        self::assertSame([
            'import_id' => 'mag-2027-01-15-9',
            'phase' => 'started',
            'horizon_start' => '2022-01-15',
            'entities' => [
                'catalog' => ['expected' => 300, 'sent' => 300],
                'customers' => ['expected' => 500, 'sent' => 498],
                'orders' => ['expected' => 1000, 'sent' => 120, 'oldest_sent_at' => '2026-02-03'],
                'buyers' => ['expected' => 650],
            ],
        ], $this->calls[0]);
        self::assertSame('started', $this->state['reported_phase']);
    }

    public function testTheCountsGoAgainAtMostEveryFiveMinutes(): void
    {
        $this->state['reported_phase'] = 'started';
        $this->state['reported_at'] = self::NOW - 299;
        $this->progress()->report();
        self::assertCount(0, $this->calls);

        $this->state['reported_at'] = self::NOW - 300;
        $this->progress()->report();
        self::assertCount(1, $this->calls);
    }

    /**
     * The start part is complete once its orders and the latest catalog and
     * customers imports' rows are delivered — reported at once, not five
     * minutes later; while a row of the start part waits, it is not.
     */
    public function testTheStartPartIsCompleteOnceItsRowsAreDelivered(): void
    {
        $this->state['reported_phase'] = 'started';
        $this->state['reported_at'] = self::NOW - 60;
        $this->state['start_part_queued_to'] = 5000;
        $this->undelivered['9:5000'] = 1;
        $this->progress()->report();
        self::assertCount(0, $this->calls, 'A start-part row still waits');

        $this->undelivered['9:5000'] = 0;
        $this->undelivered['7,8'] = 2;
        $this->progress()->report();
        self::assertCount(0, $this->calls, 'Customer rows still wait');

        $this->undelivered['7,8'] = 0;
        $this->progress()->report();
        self::assertSame('start_part_complete', $this->calls[0]['phase'] ?? null);
        self::assertTrue($this->state['start_part_done']);
    }

    public function testAQueuedOrRunningCustomersImportHoldsTheStartPartBack(): void
    {
        $this->state['start_part_queued_to'] = 5000;
        $this->jobs[8] = $this->job(8, Job::TYPE_CUSTOMERS, Job::STATUS_RUNNING, 500, 20);
        $this->progress()->report();

        self::assertSame('started', $this->calls[0]['phase']);
    }

    public function testTheImportIsCompleteOnceTheOrdersImportCompletedAndItsRowsAreDelivered(): void
    {
        $this->state['reported_phase'] = 'start_part_complete';
        $this->state['reported_at'] = self::NOW - 60;
        $this->state['start_part_queued_to'] = 5000;
        $this->state['start_part_done'] = true;
        $this->jobs[9] = $this->job(9, Job::TYPE_ORDERS, Job::STATUS_COMPLETED, 1000, 996);
        $this->undelivered['9'] = 3;
        $this->progress()->report();
        self::assertCount(0, $this->calls);

        $this->undelivered['9'] = 0;
        $this->progress()->report();
        self::assertSame('complete', $this->calls[0]['phase']);
        self::assertSame(996, $this->calls[0]['entities']['orders']['sent']);

        $this->state['reported_at'] = self::NOW - 3600;
        $this->progress()->report();
        self::assertCount(1, $this->calls, 'Nothing after complete');
    }

    public function testAFailedCallIsLoggedAndTriedAgainFiveMinutesLater(): void
    {
        $this->engineFails = new EngineTransportException('Engine unavailable', 503);
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('info')->with(
            'Campaign Intelligence history progress report failed',
            self::callback(static fn (array $context): bool => $context['phase'] === 'started')
        );
        $this->progress($logger)->report();
        self::assertSame(self::NOW, $this->state['failed_at']);
        self::assertNull($this->state['reported_phase']);

        $this->engineFails = null;
        $this->state['failed_at'] = self::NOW - 299;
        $this->progress()->report();
        self::assertCount(1, $this->calls, 'One failed attempt, no retry yet');

        $this->state['failed_at'] = self::NOW - 300;
        $this->progress()->report();
        self::assertCount(2, $this->calls);
        self::assertSame('started', $this->state['reported_phase']);
    }

    public function testACancelledOrdersImportOrASendingStopReportsNothing(): void
    {
        $this->jobs[9] = $this->job(9, Job::TYPE_ORDERS, Job::STATUS_CANCELLED, 1000, 50);
        $this->progress()->report();
        self::assertCount(0, $this->calls);

        $this->jobs[9] = $this->job(9, Job::TYPE_ORDERS, Job::STATUS_RUNNING, 1000, 50);
        $this->progress(null, false)->report();
        self::assertCount(0, $this->calls);
    }

    /**
     * The Orders card: the start part's share while it runs, then the share
     * of the whole horizon the engine has.
     */
    public function testTheCardShowsTheRunningPart(): void
    {
        self::assertSame(
            ['part' => 'start', 'sent' => 120, 'expected' => 400, 'percent' => 30,
                'start_months' => 18, 'horizon_months' => 60],
            $this->progress()->card($this->jobs[9])
        );

        $this->state['start_part_done'] = true;
        $this->jobs[9] = $this->job(9, Job::TYPE_ORDERS, Job::STATUS_RUNNING, 1000, 455);
        self::assertSame(
            ['part' => 'background', 'sent' => 455, 'expected' => 1000, 'percent' => 45,
                'start_months' => 18, 'horizon_months' => 60],
            $this->progress()->card($this->jobs[9])
        );

        self::assertNull($this->progress()->card($this->job(4, Job::TYPE_ORDERS, Job::STATUS_RUNNING, 10, 1)));
    }

    private function progress(?Logger $logger = null, bool $sendingAllowed = true): HistoryProgress
    {
        $historyImport = $this->createMock(HistoryImport::class);
        $historyImport->method('state')->willReturnCallback(fn (): array => $this->state);
        $historyImport->method('update')->willReturnCallback(function (array $changes): array {
            $this->state = array_merge($this->state, $changes);

            return $this->state;
        });

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->method('get')->willReturnCallback(fn (int $id): ?Job => $this->jobs[$id] ?? null);
        $jobManager->method('latest')->willReturnCallback(function (string $type): ?Job {
            foreach ($this->jobs as $job) {
                if ($job->getJobType() === $type) {
                    return $job;
                }
            }

            return null;
        });

        $queue = $this->createMock(IngestQueue::class);
        $queue->method('countUndelivered')->willReturnCallback(
            fn (array $jobIds, ?int $upTo = null): int => $this->undelivered[
                implode(',', $jobIds) . ($upTo === null ? '' : ':' . $upTo)
            ] ?? 0
        );
        $queue->method('lastSentPayload')->willReturn(['ordered_at' => '2026-02-03T10:00:00Z']);

        $client = $this->createMock(Client::class);
        $client->method('historyProgress')->willReturnCallback(function (array $body): array {
            $this->calls[] = $body;
            if ($this->engineFails !== null) {
                throw $this->engineFails;
            }

            return ['ok' => true];
        });

        $settings = $this->createMock(Settings::class);
        $settings->method('isSendingAllowed')->willReturn($sendingAllowed);
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);

        return new HistoryProgress(
            $historyImport,
            $jobManager,
            $queue,
            $client,
            $settings,
            $dateTime,
            $logger ?? $this->createMock(Logger::class)
        );
    }

    private function job(int $id, string $type, string $status, int $total, int $sent): Job&MockObject
    {
        $job = $this->createMock(Job::class);
        $job->method('getId')->willReturn($id);
        $job->method('getJobType')->willReturn($type);
        $job->method('getStatus')->willReturn($status);
        $job->method('getData')->willReturnCallback(
            static fn (string $key = '') => $key === 'total_count' ? $total : null
        );
        $job->method('getSentCount')->willReturn($sent);
        $job->method('getProcessedCount')->willReturn($sent);

        return $job;
    }
}
