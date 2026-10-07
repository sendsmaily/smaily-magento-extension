<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Cron;

use Magento\Framework\FlagManager;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Cron\HealthCheck;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\ConsentSource;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Health\QueueHealth;
use Smaily\Connect\Model\Logger\Logger;

/**
 * A deactivated account is a verdict, not an outage, and the two need
 * opposite wording: an outage is waited out, a verdict is acted on
 * (PRO-2451 / PRO-1953).
 */
class HealthCheckTest extends TestCase
{
    public function testARefusedAccountIsNamedAndTheOutageClockIsDropped(): void
    {
        $flags = $this->createMock(FlagManager::class);
        $saved = [];
        $flags->method('saveFlag')->willReturnCallback(
            static function (string $flag) use (&$saved): bool {
                $saved[] = $flag;

                return true;
            }
        );
        $deleted = [];
        $flags->method('deleteFlag')->willReturnCallback(
            static function (string $flag) use (&$deleted): bool {
                $deleted[] = $flag;

                return true;
            }
        );

        $notifier = $this->createMock(NotifierInterface::class);
        $title = '';
        $body = '';
        $notifier->expects(self::once())->method('addMajor')->willReturnCallback(
            static function (string $t, string $b) use (&$title, &$body): void {
                $title = $t;
                $body = $b;
            }
        );

        $this->createCron(true, $flags, $notifier)->execute();

        self::assertContains(HealthCheck::FLAG_ENGINE_DOWN_SINCE, $deleted);
        self::assertNotContains(HealthCheck::FLAG_ENGINE_DOWN_SINCE, $saved);
        self::assertStringContainsString('not active', $title);
        self::assertStringNotContainsString('recover', $body);
    }

    public function testAnUnreachableEngineStillStartsTheOutageClock(): void
    {
        $flags = $this->createMock(FlagManager::class);
        $flags->expects(self::once())->method('saveFlag')
            ->with(HealthCheck::FLAG_ENGINE_DOWN_SINCE, self::anything());
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects(self::never())->method('addMajor');

        $this->createCron(false, $flags, $notifier)->execute();
    }

    private function createCron(
        bool $refused,
        FlagManager&MockObject $flags,
        NotifierInterface&MockObject $notifier
    ): HealthCheck {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);
        $settings->method('isRefused')->willReturn($refused);

        $client = $this->createMock(Client::class);
        $client->method('ping')->willThrowException(new EngineRequestException('HTTP 403', 403));

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_757_000_000);

        $queueHealth = $this->createMock(QueueHealth::class);
        $queueHealth->method('failedSince')->willReturn(0);

        return new HealthCheck(
            $settings,
            $client,
            $flags,
            $notifier,
            $queueHealth,
            $dateTime,
            $this->createMock(Logger::class),
            $this->createMock(ConsentSource::class)
        );
    }

    /**
     * Browse tracking on without Magento's cookie restriction mode collects
     * nothing unless the store added the consent override, so the admin
     * says so and names both ways to connect a consent source (PRO-3664,
     * the WooCommerce plugin's needs_consent_api_notice).
     */
    public function testBrowseTrackingWithoutCookieRestrictionNamesBothConsentSources(): void
    {
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturn(null);
        $flags->expects(self::once())->method('saveFlag')
            ->with(HealthCheck::FLAG_CONSENT_SOURCE_NOTIFIED, 1);

        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects(self::once())->method('addMinor')->with(
            self::stringContains('until a consent source is connected'),
            self::logicalAnd(
                self::stringContains('Cookie Restriction Mode'),
                self::stringContains('Stores > Configuration > General > Web > Default Cookie Settings'),
                self::stringContains('consent override')
            ),
            ConsentSource::GUIDE_URL
        );

        $this->createConsentCron(true, false, $flags, $notifier)->execute();
    }

    public function testTheConsentSourceNoticeIsPostedOnce(): void
    {
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(
            static fn (string $flag) => $flag === HealthCheck::FLAG_CONSENT_SOURCE_NOTIFIED ? 1 : null
        );
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects(self::never())->method('addMinor');

        $this->createConsentCron(true, false, $flags, $notifier)->execute();
    }

    /**
     * @dataProvider consentSourcePresentProvider
     */
    public function testNoConsentSourceNoticeWhenNothingIsMissing(bool $browseTracking, bool $sourcePresent): void
    {
        $flags = $this->createMock(FlagManager::class);
        $flags->expects(self::once())->method('deleteFlag')
            ->with(HealthCheck::FLAG_CONSENT_SOURCE_NOTIFIED);
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects(self::never())->method('addMinor');

        $this->createConsentCron($browseTracking, $sourcePresent, $flags, $notifier)->execute();
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function consentSourcePresentProvider(): array
    {
        return [
            'browse tracking off' => [false, false],
            // Cookie restriction mode on, or a Storefront URL saved where it
            // is off (ConsentSourceTest, PRO-3918).
            'a consent source in every store view' => [true, true],
        ];
    }

    private function createConsentCron(
        bool $browseTracking,
        bool $sourcePresent,
        FlagManager&MockObject $flags,
        NotifierInterface&MockObject $notifier
    ): HealthCheck {
        // Not connected: the engine check stays out of the way.
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(false);
        $settings->method('isBrowseTrackingEnabled')->willReturn($browseTracking);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_757_000_000);

        $queueHealth = $this->createMock(QueueHealth::class);
        $queueHealth->method('failedSince')->willReturn(0);

        $consentSource = $this->createMock(ConsentSource::class);
        $consentSource->method('isMissing')->willReturn(!$sourcePresent);

        return new HealthCheck(
            $settings,
            $this->createMock(Client::class),
            $flags,
            $notifier,
            $queueHealth,
            $dateTime,
            $this->createMock(Logger::class),
            $consentSource
        );
    }
}
