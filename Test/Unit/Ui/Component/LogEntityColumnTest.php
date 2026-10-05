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
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Ui\Component\LogEntityColumn;

/**
 * PRO-3765: a profiling-consent row's Entity reads as the short form of the
 * shopper's keyed hash; every other row's as stored.
 */
class LogEntityColumnTest extends TestCase
{
    public function testAConsentRowShowsTheShortHashAndOtherRowsTheirEntity(): void
    {
        $context = $this->createMock(ContextInterface::class);
        $context->method('getProcessor')->willReturn($this->createMock(Processor::class));
        $column = new LogEntityColumn(
            $context,
            $this->createMock(UiComponentFactory::class),
            [],
            ['name' => 'entity_id']
        );
        $hash = hash_hmac('sha256', 'u1@example.invalid', 'unit-test-crypt-key');

        $dataSource = $column->prepareDataSource(['data' => ['items' => [
            ['log_id' => 'smaily-1', 'type' => EventType::ENGINE_PROFILING_CONSENT, 'entity_id' => $hash],
            ['log_id' => 'smaily-2', 'type' => EventType::CONTACT_SYNC, 'entity_id' => 'u1@example.invalid'],
            ['log_id' => 'intelligence-3', 'type' => 'orders', 'entity_id' => '100000001'],
        ]]]);

        self::assertSame(
            [substr($hash, 0, 12) . '…', 'u1@example.invalid', '100000001'],
            array_column($dataSource['data']['items'], 'entity_id')
        );
    }
}
