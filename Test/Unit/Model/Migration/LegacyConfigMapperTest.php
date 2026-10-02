<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Migration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Migration\LegacyConfigMapper;
use Smaily\Connect\Model\SubdomainNormalizer;

class LegacyConfigMapperTest extends TestCase
{
    private LegacyConfigMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new LegacyConfigMapper(new SubdomainNormalizer());
    }

    public function testFullLegacyConfigMapsToV3Paths(): void
    {
        $result = $this->mapper->map([
            'general/subdomain' => 'https://demo.sendsmaily.net',
            'general/username' => ' api-user ',
            'general/password' => 'plaintext-secret',
            'subscribe/enableNewsletterSubscriptions' => '1',
            'subscribe/workflowId' => '55',
            'sync/enableCronSync' => '1',
            'sync/fields' => 'first_name,last_name,gender,bogus_field',
            'sync/frequency' => '0 */4 * * *',
            'abandoned/enableAbandonedCart' => '1',
            'abandoned/autoresponderId' => '77',
            'abandoned/syncTime' => '2:hour',
        ]);

        $configs = [];
        $encrypted = [];
        foreach ($result['configs'] as $config) {
            $configs[$config['path']] = $config['value'];
            if (in_array(LegacyConfigMapper::FLAG_ENCRYPT, $config['flags'], true)) {
                $encrypted[] = $config['path'];
            }
        }

        self::assertSame('demo', $configs[Config::XML_PATH_SUBDOMAIN]);
        self::assertSame('api-user', $configs[Config::XML_PATH_USERNAME]);
        self::assertSame('plaintext-secret', $configs[Config::XML_PATH_PASSWORD]);
        self::assertSame([Config::XML_PATH_PASSWORD], $encrypted, 'Legacy plaintext password must be encrypted');

        self::assertSame('55', $configs[Config::XML_PATH_WELCOME_WORKFLOW]);
        self::assertSame('1', $configs[Config::XML_PATH_WELCOME_ENABLED]);

        self::assertSame('1', $configs[Config::XML_PATH_SYNC_ENABLED]);
        // The legacy `gender` selection lands on the v3 field id, which is also
        // the cross-platform wire key — an upgraded store keeps its tick.
        self::assertSame('first_name,last_name,user_gender', $configs[Config::XML_PATH_SYNC_FIELDS]);

        self::assertSame('1', $configs[Config::XML_PATH_ABANDONED_ENABLED]);
        self::assertSame('77', $configs[Config::XML_PATH_ABANDONED_WORKFLOW]);
        self::assertSame('120', $configs[Config::XML_PATH_ABANDONED_CUTOFF]);
        self::assertNotEmpty($result['notices']); // frequency drop notice
    }

    public function testDisabledFeaturesMapToZeroWithoutWorkflows(): void
    {
        $result = $this->mapper->map([
            'sync/enableCronSync' => '0',
            'abandoned/enableAbandonedCart' => '0',
            'subscribe/workflowId' => '0',
        ]);

        $configs = [];
        foreach ($result['configs'] as $config) {
            $configs[$config['path']] = $config['value'];
        }

        self::assertSame('0', $configs[Config::XML_PATH_SYNC_ENABLED]);
        self::assertSame('0', $configs[Config::XML_PATH_ABANDONED_ENABLED]);
        self::assertArrayNotHasKey(Config::XML_PATH_WELCOME_WORKFLOW, $configs);
    }

    public function testEnableModuleNoSwitchesSyncWelcomeAndAbandonedCartOffAtThatScope(): void
    {
        $result = $this->mapper->map([
            'general/enable' => '0',
            'subscribe/enableNewsletterSubscriptions' => '1',
            'subscribe/workflowId' => '55',
            'sync/enableCronSync' => '1',
            'abandoned/enableAbandonedCart' => '1',
            'abandoned/autoresponderId' => '77',
        ]);

        $switches = [];
        foreach ($result['configs'] as $config) {
            if (in_array($config['path'], [
                Config::XML_PATH_SYNC_ENABLED,
                Config::XML_PATH_WELCOME_ENABLED,
                Config::XML_PATH_ABANDONED_ENABLED,
            ], true)) {
                $switches[] = $config['path'] . '=' . $config['value'];
            }
        }

        self::assertSame([
            Config::XML_PATH_SYNC_ENABLED . '=0',
            Config::XML_PATH_WELCOME_ENABLED . '=0',
            Config::XML_PATH_ABANDONED_ENABLED . '=0',
        ], $switches, 'One row each, off');
        self::assertContains(
            ['path' => Config::XML_PATH_ABANDONED_WORKFLOW, 'value' => '77', 'flags' => []],
            $result['configs'],
            'The workflows still carry over'
        );
    }

    /**
     * @dataProvider enableModuleOnProvider
     *
     * @param array<string, string> $enable
     */
    public function testEnableModuleYesOrAbsentWritesNothingExtra(array $enable): void
    {
        $result = $this->mapper->map($enable + ['sync/enableCronSync' => '1']);

        self::assertSame(
            [['path' => Config::XML_PATH_SYNC_ENABLED, 'value' => '1', 'flags' => []]],
            $result['configs']
        );
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function enableModuleOnProvider(): array
    {
        return [
            'yes' => [['general/enable' => '1']],
            'absent' => [[]],
        ];
    }

    public function testCaptchaSettingsProduceANotice(): void
    {
        $result = $this->mapper->map([
            'subscribe/enableCaptcha' => '1',
            'subscribe/captchaType' => 'google_captcha',
        ]);

        self::assertSame([], $result['configs']);
        self::assertStringContainsString('reCAPTCHA', $result['notices'][0]);
    }

    /**
     * @dataProvider intervalProvider
     */
    public function testIntervalConversion(string $interval, int $expectedMinutes): void
    {
        self::assertSame($expectedMinutes, $this->mapper->intervalToMinutes($interval));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function intervalProvider(): array
    {
        return [
            '20 minutes' => ['20:minutes', 20],
            '30 minutes' => ['30:minutes', 30],
            '1 hour' => ['1:hour', 60],
            '12 hours' => ['12:hour', 720],
            'below minimum clamps' => ['5:minutes', 10],
            'garbage clamps to minimum' => ['garbage', 10],
        ];
    }
}
