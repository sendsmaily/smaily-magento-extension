<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Plugin\Engine;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Plugin\Engine\SourceDeduction;

/**
 * PRO-1951: on a default 2.4.x install the real "order decrement" is the
 * shipment's source deduction (and the credit-memo return to stock is its
 * mirror) — neither goes through SourceItemsSave, so this seam is the only
 * hook that sees them. It never names an MSI type — MSI is removable — so the
 * sku is read by duck typing off the deduction request's items.
 */
class SourceDeductionTest extends TestCase
{
    private CatalogIngest&MockObject $catalogIngest;

    protected function setUp(): void
    {
        $this->catalogIngest = $this->createMock(CatalogIngest::class);
    }

    public function testADeductionRequestIsReadThroughItsItems(): void
    {
        $this->catalogIngest->expects(self::once())->method('markSkusChanged')->with(['TENT-1', 'TENT-1']);

        $this->plugin()->afterExecute(new \stdClass(), null, $this->deductionRequest('TENT-1', 'TENT-1'));
    }

    /**
     * PRO-1967: a multi-line shipment is one call — one sku lookup and one
     * insert inside the order transaction, not one product build per line.
     */
    public function testEveryLineOfAShipmentIsHandedOverInOneCall(): void
    {
        $this->catalogIngest->expects(self::once())->method('markSkusChanged')
            ->with(['TENT-1', 'MUG-2', 'TENT-1', 'CAP-3']);

        $this->plugin()->afterExecute(
            new \stdClass(),
            null,
            $this->deductionRequest('TENT-1', 'MUG-2', 'TENT-1', 'CAP-3')
        );
    }

    public function testAnUnrecognisedPayloadQueuesNothing(): void
    {
        $this->catalogIngest->expects(self::never())->method('markSkusChanged');

        $this->plugin()->afterExecute(new \stdClass(), null, 'nonsense');
    }

    public function testTheSubjectResultIsPassedThrough(): void
    {
        self::assertSame('kept', $this->plugin()->afterExecute(new \stdClass(), 'kept', null));
    }

    private function plugin(): SourceDeduction
    {
        return new SourceDeduction($this->catalogIngest);
    }

    private function deductionRequest(string ...$skus): object
    {
        $items = array_map(
            static fn (string $sku): object => new class ($sku) {
                public function __construct(private readonly string $sku)
                {
                }

                public function getSku(): string
                {
                    return $this->sku;
                }
            },
            $skus
        );

        return new class ($items) {
            /** @param object[] $items */
            public function __construct(private readonly array $items)
            {
            }

            /** @return object[] */
            public function getItems(): array
            {
                return $this->items;
            }
        };
    }
}
