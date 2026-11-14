<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Report;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Report\Credentials;
use Smaily\Connect\Model\Report\EventReport;
use Smaily\Connect\Model\Report\InstallationReport;
use Smaily\Connect\Model\Report\ReportBuilder;
use Smaily\Connect\Model\Report\SetupChange;

/**
 * CON-234: a save that removes a Smaily account the installation held
 * sends `disconnected` at once, with the main account captured before the
 * save; a save that went through schedules a snapshot.
 */
class SetupChangeTest extends TestCase
{
    /** @var array<int, string[]> what accountKeys() answers, call by call */
    private array $keys = [];

    /** @var array<int, array<int, mixed>> */
    private array $events = [];

    private int $scheduled = 0;

    public function testASaveThatKeepsEveryAccountOnlySchedulesASnapshot(): void
    {
        $this->keys = [['marketing|example'], ['marketing|example', 'transactional|example-tx']];
        $change = $this->change();

        $change->after($change->before());

        self::assertSame([], $this->events);
        self::assertSame(1, $this->scheduled);
    }

    public function testASaveThatRemovesAnAccountReportsItAtOnce(): void
    {
        $this->keys = [['marketing|example', 'transactional|example-tx'], ['marketing|example']];
        $change = $this->change();

        $change->after($change->before());

        self::assertCount(1, $this->events);
        self::assertSame(ReportBuilder::EVENT_DISCONNECTED, $this->events[0][0]);
        self::assertSame([], $this->events[0][1]);
        self::assertEquals(new Credentials('example', 'api-user', 'secret'), $this->events[0][2]);
        self::assertSame(1, $this->scheduled);
    }

    public function testARefusedSaveSchedulesNothing(): void
    {
        $this->keys = [['marketing|example'], ['marketing|example']];
        $change = $this->change();

        $change->after($change->before(), false);

        self::assertSame(0, $this->scheduled);
    }

    public function testAFailedReadNeverStopsTheSave(): void
    {
        $builder = $this->createMock(ReportBuilder::class);
        $builder->method('accountKeys')->willThrowException(new \RuntimeException('database gone'));
        $change = new SetupChange(
            $builder,
            $this->createMock(EventReport::class),
            $this->createMock(InstallationReport::class),
            $this->createMock(Logger::class)
        );

        $change->after($change->before());

        self::assertSame(['accounts' => [], 'main' => null], $change->before());
    }

    private function change(): SetupChange
    {
        $builder = $this->createMock(ReportBuilder::class);
        $builder->method('accountKeys')->willReturnCallback(fn (): array => array_shift($this->keys) ?? []);
        $builder->method('mainCredentials')->willReturn(new Credentials('example', 'api-user', 'secret'));
        $eventReport = $this->createMock(EventReport::class);
        $eventReport->method('send')->willReturnCallback(function (mixed ...$args): string {
            $this->events[] = $args;

            return 'http=200 error=-';
        });
        $installationReport = $this->createMock(InstallationReport::class);
        $installationReport->method('setupSaved')->willReturnCallback(function (): void {
            $this->scheduled++;
        });

        return new SetupChange($builder, $eventReport, $installationReport, $this->createMock(Logger::class));
    }
}
