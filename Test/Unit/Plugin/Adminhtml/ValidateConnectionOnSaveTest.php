<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Plugin\Adminhtml;

use Magento\Config\Model\Config as SystemConfig;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Client\CredentialCheck;
use Smaily\Connect\Model\Client\Exception\AuthenticationException;
use Smaily\Connect\Model\Client\Exception\PlanBlockedException;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\SubdomainNormalizer;
use Smaily\Connect\Plugin\Adminhtml\ValidateConnectionOnSave;

/**
 * The configuration save names the cause of a refused check (PRO-3579): a
 * package without API access is not reported as rejected credentials.
 */
class ValidateConnectionOnSaveTest extends TestCase
{
    /** @var string[] */
    private array $errors = [];

    public function testAPackageWithoutApiAccessIsNamedAsTheCause(): void
    {
        $this->save(new PlanBlockedException('plan', 403));

        self::assertCount(1, $this->errors);
        self::assertStringContainsString('package does not include API access', $this->errors[0]);
        self::assertStringNotContainsString('rejected', $this->errors[0]);
    }

    public function testRefusedCredentialsAreStillReportedAsRejected(): void
    {
        $this->save(new AuthenticationException('refused', 401));

        self::assertCount(1, $this->errors);
        self::assertStringContainsString('Smaily rejected the API credentials', $this->errors[0]);
    }

    private function save(\Throwable $refusal): void
    {
        $subject = $this->getMockBuilder(SystemConfig::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->addMethods(['getSection', 'getStore'])
            ->getMock();
        $subject->method('getSection')->willReturn('smaily_connect');
        $subject->method('getStore')->willReturn('');
        $subject->method('getData')->with('groups')->willReturn(['connection' => ['fields' => [
            'subdomain' => ['value' => 'demo'],
            'username' => ['value' => 'user'],
            'password' => ['value' => 'secret'],
        ]]]);

        $credentialCheck = $this->createMock(CredentialCheck::class);
        $credentialCheck->method('resolvePassword')->willReturnArgument(0);
        $credentialCheck->method('check')->willThrowException($refusal);

        $normalizer = $this->createMock(SubdomainNormalizer::class);
        $normalizer->method('normalize')->willReturnArgument(0);

        $messageManager = $this->createMock(ManagerInterface::class);
        $messageManager->method('addErrorMessage')->willReturnCallback(function (string $message) use ($messageManager) {
            $this->errors[] = $message;

            return $messageManager;
        });

        (new ValidateConnectionOnSave(
            $credentialCheck,
            $this->createMock(Config::class),
            $normalizer,
            $messageManager,
            $this->createMock(Logger::class)
        ))->beforeSave($subject);
    }
}
