<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Ui\Component;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Log\StatusPill;
use Smaily\Connect\Ui\Component\LogStatusColumn;

/**
 * PRO-3565: each grid row carries the pill its status is drawn with.
 */
class LogStatusColumnTest extends TestCase
{
    public function testEachRowCarriesThePillOfItsStatus(): void
    {
        $context = $this->createMock(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createMock(Processor::class));
        $column = new LogStatusColumn(
            $context,
            $this->createMock(UiComponentFactory::class),
            new StatusPill(),
            [],
            ['name' => 'status']
        );

        $dataSource = $column->prepareDataSource(['data' => ['items' => [
            ['log_id' => 'smaily-1', 'status' => 'failed'],
            ['log_id' => 'smaily-2', 'status' => 'skipped'],
            ['log_id' => 'intelligence-3', 'status' => 'sent'],
        ]]]);

        self::assertSame(
            ['failed', 'neutral', 'sent'],
            array_column($dataSource['data']['items'], 'status_pill')
        );
    }
}
