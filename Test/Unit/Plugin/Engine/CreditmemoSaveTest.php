<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Plugin\Engine;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo as CreditmemoResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\OrderIngest;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Plugin\Engine\CreditmemoSave;

/**
 * PRO-1955: a new credit memo queues its order — a zero-value one too,
 * which moves no refunded total and so passes no order-save gate.
 */
class CreditmemoSaveTest extends TestCase
{
    private OrderIngest&MockObject $orderIngest;

    protected function setUp(): void
    {
        $this->orderIngest = $this->createMock(OrderIngest::class);
    }

    public function testANewCreditMemoQueuesItsOrderAsARefund(): void
    {
        $order = $this->order();
        $this->orderIngest->expects(self::once())->method('enqueue')->with($order, true);

        $result = $this->plugin()->afterSave($this->resource(), 'saved', $this->creditmemo($order, null));

        self::assertSame('saved', $result);
    }

    /**
     * A credit memo loaded from the database and saved again is no new
     * refund.
     */
    public function testALaterSaveOfAnExistingCreditMemoQueuesNothing(): void
    {
        $this->orderIngest->expects(self::never())->method('enqueue');

        $this->plugin()->afterSave($this->resource(), null, $this->creditmemo($this->order(), '12'));
    }

    public function testNothingIsQueuedWhileCampaignIntelligenceIsNotConnected(): void
    {
        $this->orderIngest->expects(self::never())->method('enqueue');

        $this->plugin(false)->afterSave($this->resource(), null, $this->creditmemo($this->order(), null));
    }

    private function plugin(bool $connected = true): CreditmemoSave
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn($connected);

        return new CreditmemoSave($settings, $this->orderIngest);
    }

    private function resource(): CreditmemoResource
    {
        return $this->createMock(CreditmemoResource::class);
    }

    private function creditmemo(Order $order, ?string $origEntityId): Creditmemo
    {
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getId')->willReturn(12);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getOrigData')->willReturnCallback(
            static fn (?string $key = null): ?string => $key === 'entity_id' ? $origEntityId : null
        );

        return $creditmemo;
    }

    private function order(): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(5);

        return $order;
    }
}
