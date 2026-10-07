<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Console\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Console\Command\GdprCommand;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Privacy\LocalEraser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * PRO-2465: while Campaign Intelligence refuses the account (contract §2
 * `403 tenant_inactive`, remembered by PRO-2451), `smaily:gdpr` does not ask
 * the engine and says in plain words that the account is not active and what
 * to do. The store's own data is still exported and erased (PRO-2452).
 */
class GdprCommandTest extends TestCase
{
    private const EMAIL = 'jane@example.com';

    private Client&MockObject $client;

    private LocalEraser&MockObject $localEraser;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->localEraser = $this->createMock(LocalEraser::class);
    }

    public function testARefusedAccountStillErasesTheLocalDataAndSaysWhatToDo(): void
    {
        $this->localEraser->expects(self::once())->method('erase')->with(self::EMAIL)
            ->willReturn(['Queued messages' => ['removed' => 2, 'anonymised' => 1]]);
        $this->client->expects(self::never())->method('customerDelete');

        $tester = $this->runCommand(['action' => 'erase', 'email' => self::EMAIL, '--force' => true], true);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $this->oneLine($tester->getDisplay());
        self::assertStringContainsString('Queued messages: 2 removed, 1 anonymized', $display);
        self::assertStringContainsString(
            'Local data is erased, but Campaign Intelligence data was not: the Campaign Intelligence account is'
                . ' not active. Ask Smaily to make the account active again, then run the command again.',
            $display
        );
    }

    public function testARefusedAccountStillExportsTheLocalDataAndSaysWhatToDo(): void
    {
        $this->localEraser->method('export')->willReturn(['Queued messages' => []]);
        $this->client->expects(self::never())->method('customerExport');

        $tester = $this->runCommand(['action' => 'export', 'email' => self::EMAIL], true);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('"local": {', $display);
        self::assertStringContainsString('"engine": null', $display);
        self::assertStringContainsString(
            'Campaign Intelligence data was not exported: the Campaign Intelligence account is not active.'
                . ' Ask Smaily to make the account active again, then run the command again.',
            $this->oneLine($display)
        );
    }

    public function testAnActiveAccountErasesTheEngineDataToo(): void
    {
        $this->localEraser->method('erase')->willReturn([]);
        $this->client->expects(self::once())->method('customerDelete')->with(self::EMAIL)->willReturn([]);

        $tester = $this->runCommand(['action' => 'erase', 'email' => self::EMAIL, '--force' => true], false);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Erased engine data for ' . self::EMAIL, $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runCommand(array $arguments, bool $refused): CommandTester
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);
        $settings->method('isRefused')->willReturn($refused);

        $tester = new CommandTester(new GdprCommand($settings, $this->client, $this->localEraser));
        $tester->execute($arguments);

        return $tester;
    }

    private function oneLine(string $display): string
    {
        return (string)preg_replace('/\s+/', ' ', $display);
    }
}
