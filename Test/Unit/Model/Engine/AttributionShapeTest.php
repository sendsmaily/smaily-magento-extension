<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\AttributionShape;

/**
 * A visitor token takes either form the contract allows: an engine-issued
 * `vt_` token or a store-created `vs_` token, `vs_` + exactly 22
 * alphanumerics (contract 1.11.0, PRO-3912). Values are synthetic.
 */
class AttributionShapeTest extends TestCase
{
    /**
     * @dataProvider visitorTokens
     */
    public function testAVisitorTokenIsAcceptedOnlyInAFormTheContractAllows(string $value, bool $expected): void
    {
        self::assertSame($expected, AttributionShape::isVisitorToken($value));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function visitorTokens(): array
    {
        return [
            'engine vt_ token' => ['vt_8f3k2a', true],
            'engine vt_ token at 64 characters' => ['vt_' . str_repeat('A', 61), true],
            'store-created vs_ token' => ['vs_4fK9a2LmQ7xZ0bR3tY8wP1', true],
            'vs_ with 21 characters' => ['vs_4fK9a2LmQ7xZ0bR3tY8wP', false],
            'vs_ with 23 characters' => ['vs_4fK9a2LmQ7xZ0bR3tY8wP1c', false],
            'vs_ with an underscore' => ['vs_4fK9a2LmQ7xZ0bR3tY8w_1', false],
            'vs_ with a trailing newline' => ["vs_4fK9a2LmQ7xZ0bR3tY8wP1\n", false],
            'vt_ over 64 characters' => ['vt_' . str_repeat('a', 62), false],
            'another prefix' => ['vx_4fK9a2LmQ7xZ0bR3tY8wP1', false],
            'no prefix' => ['abc123', false],
            'empty' => ['', false],
        ];
    }
}
