<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Client;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Client\Exception\ApiException;
use Smaily\Connect\Model\Client\FailureText;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Test\Unit\Support\StoreLocale;

/**
 * CON-357: what a queue row's error, the Log and the log file keep of a
 * failure — a client's own message, or another error's type, code and
 * place, never the database's text.
 */
class FailureTextTest extends TestCase
{
    protected function tearDown(): void
    {
        StoreLocale::reset();
    }

    public function testASmailyFailureKeepsTheClientsEnglishMessage(): void
    {
        StoreLocale::use('et_EE');

        self::assertSame(
            'Smaily API returned code 203',
            FailureText::of(new ApiException(__('Smaily API returned code %1', 203), 203))
        );
    }

    public function testAnEngineFailureKeepsTheClientsMessage(): void
    {
        self::assertSame(
            'Engine request failed with HTTP 404: customer_not_found',
            FailureText::of(new EngineRequestException('Engine request failed with HTTP 404: customer_not_found', 404))
        );
    }

    public function testAnyOtherErrorKeepsItsTypeCodeAndPlaceNeverItsText(): void
    {
        $text = FailureText::of(new \PDOException(
            "SQLSTATE[23000]: Duplicate entry 'mari@example.com' for key 'email' (Mari Maasikas)",
            23000
        ));

        self::assertMatchesRegularExpression('/^PDOException at FailureTextTest\.php:\d+ \(code 23000\)$/', $text);
        self::assertStringNotContainsString('mari@example.com', $text);
        self::assertStringNotContainsString('Maasikas', $text);
    }
}
