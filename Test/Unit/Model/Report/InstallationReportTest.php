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
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Report\Credentials;
use Smaily\Connect\Model\Report\InstallationReport;
use Smaily\Connect\Model\Report\Report;
use Smaily\Connect\Model\Report\ReportBuilder;
use Smaily\Connect\Model\Report\ReportSender;
use Smaily\Connect\Model\Report\SendResult;

/**
 * CON-234: when a snapshot goes (contract installation-report-v1 "When an
 * installation sends a report" and "What the plugin does with each
 * answer"): five minutes after a save, more saves adding none; daily, 24
 * hours after the last attempt, the first at a random moment within a day;
 * a 429, a 5xx or no answer tried again after 60/120/240 s plus 0–30 s or a
 * longer Retry-After, never over an hour; nothing without a connected
 * account; one report per cron run.
 */
class InstallationReportTest extends TestCase
{
    private const START = 1_762_300_000;

    /** @var array<string, mixed> */
    private array $flag = [];

    private int $now = self::START;

    private bool $connected = true;

    /** @var array<int, string> the triggers sent, in order */
    private array $sent = [];

    /** @var array<int, SendResult> the service's answers, in order */
    private array $answers = [];

    /** @var array<int, array{string, string}> */
    private array $logged = [];

    /** The random number the next draw gives (jitter or the first daily moment). */
    private int $random = 0;

    public function testASaveSchedulesOneReportFiveMinutesOnAndMoreSavesAddNone(): void
    {
        $this->random = 80000;
        $report = $this->report();
        $report->setupSaved();
        $this->now += 120;
        $report->setupSaved();

        self::assertSame(self::START + 300, $this->state()['setup_saved_at']);

        $this->now = self::START + 299;
        $report->run();
        self::assertSame([], $this->sent);

        $this->now = self::START + 300;
        $report->run();
        self::assertSame(['setup_saved'], $this->sent);
        self::assertNull($this->state()['setup_saved_at']);
    }

    public function testWithoutAConnectedAccountNothingIsScheduledOrSentAndTheScheduleIsForgotten(): void
    {
        $report = $this->report();
        $report->setupSaved();
        $this->connected = false;

        $report->setupSaved();
        $this->now += 400;
        $report->run();

        self::assertSame([], $this->sent);
        self::assertSame([], $this->flag);
    }

    public function testTheFirstDailyReportIsAtARandomMomentWithinADay(): void
    {
        $this->random = 5000;
        $report = $this->report();

        $report->run();
        self::assertSame([], $this->sent);
        self::assertSame(self::START + 5000, $this->state()['daily_at']);

        $this->now = self::START + 5000;
        $report->run();
        self::assertSame(['daily'], $this->sent);
        self::assertSame(self::START + 5000 + 86400, $this->state()['daily_at']);
    }

    public function testAReportAfterASaveKeepsTheFirstDailyMomentAndALaterOneMovesIt(): void
    {
        $this->random = 40000;
        $report = $this->report();
        $report->setupSaved();
        $this->now = self::START + 300;
        $report->run();

        self::assertSame(['setup_saved'], $this->sent);
        self::assertSame(self::START + 300 + 40000, $this->state()['daily_at'], 'The first daily one keeps its random moment.');

        $report->setupSaved();
        $this->now += 300;
        $report->run();
        self::assertSame(['setup_saved', 'setup_saved'], $this->sent);
        self::assertSame($this->now + 86400, $this->state()['daily_at'], 'The daily one is due a day after the last attempt.');
    }

    public function testOneReportPerRunASaveFirst(): void
    {
        $this->flag = ['daily_at' => self::START - 10, 'setup_saved_at' => self::START - 5];
        $report = $this->report();

        $report->run();
        $report->run();

        self::assertSame(['setup_saved'], $this->sent, 'The daily report waits a day after this attempt.');
    }

    public function testATryThatMayPassIsRepeatedThreeTimesAtTheContractsWaits(): void
    {
        $this->random = 7;
        $this->flag = ['daily_at' => self::START];
        $this->answers = array_fill(0, 4, new SendResult(503, 'smaily_unreachable', true, 30));
        $report = $this->report();

        $report->run();
        self::assertSame(['trigger' => 'daily', 'attempt' => 1, 'at' => self::START + 60 + 7], $this->state()['retry']);

        foreach ([1 => 120, 2 => 240] as $attempt => $wait) {
            $this->now = $this->state()['retry']['at'];
            $at = $this->now;
            $report->run();
            self::assertSame(['trigger' => 'daily', 'attempt' => $attempt + 1, 'at' => $at + $wait + 7], $this->state()['retry']);
        }

        $this->now = $this->state()['retry']['at'];
        $report->run();
        self::assertNull($this->state()['retry'], 'After the last try it gives up.');
        self::assertSame(['daily', 'daily', 'daily', 'daily'], $this->sent);
        self::assertSame('error', end($this->logged)[0]);
    }

    public function testALongerRetryAfterIsWaitedAndOneOverAnHourEndsTheTry(): void
    {
        $this->flag = ['daily_at' => self::START];
        $this->answers = [new SendResult(429, 'rate_limited', true, 900)];
        $report = $this->report();
        $report->run();
        self::assertSame(self::START + 900, $this->state()['retry']['at']);

        $this->now = self::START + 900;
        $this->answers = [new SendResult(429, 'rate_limited', true, 3601)];
        $report->run();
        self::assertNull($this->state()['retry']);
    }

    /**
     * @dataProvider finalAnswers
     */
    public function testAnAnswerThatCannotChangeIsLoggedOnceAndNotTriedAgain(SendResult $answer): void
    {
        $this->flag = ['daily_at' => self::START];
        $this->answers = [$answer];
        $report = $this->report();

        $report->run();
        $this->now += 3600;
        $report->run();

        self::assertSame(['daily'], $this->sent);
        self::assertNull($this->state()['retry']);
        self::assertCount(1, $this->logged);
        self::assertSame('error', $this->logged[0][0]);
    }

    /**
     * @return array<string, array{SendResult}>
     */
    public static function finalAnswers(): array
    {
        return [
            'refused credentials' => [new SendResult(401, 'invalid_smaily_credentials', false)],
            'a refused body' => [new SendResult(400, 'validation_failed', false)],
            'a redirect' => [new SendResult(308, 'redirect', false)],
        ];
    }

    public function testAnAcceptedReportLogsNothing(): void
    {
        $this->flag = ['daily_at' => self::START];
        $this->report()->run();

        self::assertSame(['daily'], $this->sent);
        self::assertSame([], $this->logged);
    }

    public function testANewerSnapshotReplacesAPendingRetry(): void
    {
        $this->flag = [
            'daily_at' => self::START + 50000,
            'setup_saved_at' => self::START,
            'retry' => ['trigger' => 'daily', 'attempt' => 2, 'at' => self::START],
        ];

        $this->report()->run();

        self::assertSame(['setup_saved'], $this->sent);
        self::assertNull($this->state()['retry']);
    }

    /**
     * The schedule as stored.
     *
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return $this->flag;
    }

    private function report(): InstallationReport
    {
        $builder = $this->createMock(ReportBuilder::class);
        $credentials = new Credentials('example', 'api-user', 'not-a-real-password');
        $builder->method('mainCredentials')->willReturnCallback(fn (): ?Credentials => $this->connected ? $credentials : null);
        $builder->method('build')->willReturnCallback(
            function (string $event, string $trigger) use ($credentials): ?Report {
                self::assertSame(ReportBuilder::EVENT_SNAPSHOT, $event);

                return $this->connected ? new Report(['trigger' => $trigger], $credentials) : null;
            }
        );
        $sender = $this->createMock(ReportSender::class);
        $sender->method('send')->willReturnCallback(function (array $body): SendResult {
            $this->sent[] = $body['trigger'];

            return array_shift($this->answers) ?? new SendResult(200, '', false);
        });
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturnCallback(fn (): array => $this->flag);
        $flagManager->method('saveFlag')->willReturnCallback(function (string $code, array $data): bool {
            self::assertSame(InstallationReport::FLAG_CODE, $code);
            $this->flag = $data;

            return true;
        });
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);
        $logger = $this->createMock(Logger::class);
        foreach (['error', 'info'] as $level) {
            $logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
                self::assertStringNotContainsString('not-a-real-password', (string)json_encode($context));
                $this->logged[] = [$level, $message];
            });
        }

        $random = fn (): int => $this->random;

        return new class ($builder, $sender, $flagManager, $dateTime, $logger, $random) extends InstallationReport {
            /** @var \Closure(): int */
            private \Closure $draw;

            public function __construct(
                ReportBuilder $builder,
                ReportSender $sender,
                FlagManager $flagManager,
                DateTime $dateTime,
                Logger $logger,
                \Closure $draw
            ) {
                parent::__construct($builder, $sender, $flagManager, $dateTime, $logger);
                $this->draw = $draw;
            }

            protected function random(int $min, int $max): int
            {
                return max($min, min($max, ($this->draw)()));
            }
        };
    }
}
