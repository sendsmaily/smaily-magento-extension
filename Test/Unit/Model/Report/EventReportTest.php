<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Report;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Report\Credentials;
use Smaily\Connect\Model\Report\EventReport;
use Smaily\Connect\Model\Report\Report;
use Smaily\Connect\Model\Report\ReportBuilder;
use Smaily\Connect\Model\Report\ReportSender;
use Smaily\Connect\Model\Report\SendResult;

/**
 * CON-234: a `disconnected` or `deactivated` event goes once, at once,
 * never tried again, and whatever happens the caller goes on.
 */
class EventReportTest extends TestCase
{
    public function testTheEventIsBuiltAndSentOnce(): void
    {
        $previous = new Credentials('example', 'api-user', 'secret');
        $builder = $this->createMock(ReportBuilder::class);
        $builder->expects(self::once())->method('build')
            ->with(ReportBuilder::EVENT_DISCONNECTED, '', ['tenant-1'], $previous)
            ->willReturn(new Report(['event' => 'disconnected'], $previous));
        $sender = $this->createMock(ReportSender::class);
        $sender->expects(self::once())->method('send')
            ->with(['event' => 'disconnected'], $previous)
            ->willReturn(new SendResult(503, 'smaily_unreachable', true, 60));

        $outcome = (new EventReport($builder, $sender))->send(ReportBuilder::EVENT_DISCONNECTED, ['tenant-1'], $previous);

        self::assertSame('http=503 error=smaily_unreachable', $outcome);
    }

    public function testNothingIsSentWithoutAReport(): void
    {
        $builder = $this->createMock(ReportBuilder::class);
        $builder->method('build')->willReturn(null);
        $sender = $this->createMock(ReportSender::class);
        $sender->expects(self::never())->method('send');

        self::assertSame(EventReport::NOT_SENT, (new EventReport($builder, $sender))->send(ReportBuilder::EVENT_DEACTIVATED));
    }

    public function testAFailureNeverReachesTheCaller(): void
    {
        $builder = $this->createMock(ReportBuilder::class);
        $builder->method('build')->willThrowException(new \RuntimeException('database gone'));

        $outcome = (new EventReport($builder, $this->createMock(ReportSender::class)))->send(ReportBuilder::EVENT_DEACTIVATED);

        self::assertSame(EventReport::NOT_SENT, $outcome);
    }
}
