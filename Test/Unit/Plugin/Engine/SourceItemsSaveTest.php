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
use Smaily\Connect\Plugin\Engine\SourceItemsSave;

/**
 * PRO-1951: MSI writes the legacy stock row with direct SQL, so this plugin is
 * the only hook that sees a POST /V1/inventory/source-items or a Sources-grid
 * save. It never names an MSI type — MSI is removable — so the sku is read by
 * duck typing off the saved source items.
 */
class SourceItemsSaveTest extends TestCase
{
    private CatalogIngest&MockObject $catalogIngest;

    protected function setUp(): void
    {
        $this->catalogIngest = $this->createMock(CatalogIngest::class);
    }

    /**
     * PRO-1967: a bulk save (an import, a Sources-grid mass action) is one
     * call — one sku lookup and one multi-row insert, not a row per sku.
     */
    public function testEverySavedSkuIsHandedOverInOneCall(): void
    {
        $this->catalogIngest->expects(self::once())->method('markSkusChanged')->with(['TENT-1', 'MUG-2']);

        $this->plugin()->afterExecute(
            new \stdClass(),
            null,
            [$this->sourceItem('TENT-1'), $this->sourceItem('MUG-2')]
        );
    }

    /**
     * The same sku on two sources is handed over as it came; CatalogIngest
     * marks the product once.
     */
    public function testTheSameSkuOnTwoSourcesIsHandedOverInTheSameCall(): void
    {
        $this->catalogIngest->expects(self::once())->method('markSkusChanged')->with(['TENT-1', 'TENT-1']);

        $this->plugin()->afterExecute(
            new \stdClass(),
            null,
            [$this->sourceItem('TENT-1'), $this->sourceItem('TENT-1')]
        );
    }

    public function testAnUnrecognisedPayloadHandsOverNoSkus(): void
    {
        $this->catalogIngest->expects(self::once())->method('markSkusChanged')->with([]);

        $this->plugin()->afterExecute(new \stdClass(), null, 'nonsense');
    }

    public function testTheSubjectResultIsPassedThrough(): void
    {
        self::assertSame('kept', $this->plugin()->afterExecute(new \stdClass(), 'kept', []));
    }

    private function plugin(): SourceItemsSave
    {
        return new SourceItemsSave($this->catalogIngest);
    }

    private function sourceItem(string $sku): object
    {
        return new class ($sku) {
            public function __construct(private readonly string $sku)
            {
            }

            public function getSku(): string
            {
                return $this->sku;
            }
        };
    }
}
