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

    /**
     * PRO-4009: 2.8.x sent the welcome email at a website when the opt-in
     * switch and the Autoresponder ID, each the website's own value else the
     * default scope's, were both set.
     *
     * @dataProvider welcomeAtAWebsiteProvider
     *
     * @param array<string, string> $website
     * @param array<string, string> $default
     */
    public function testAWebsiteWelcomeSwitchIsWhat28xResolvedThere(
        array $website,
        array $default,
        ?string $expected
    ): void {
        $configs = [];
        foreach ($this->mapper->map($website, $default)['configs'] as $config) {
            $configs[$config['path']] = $config['value'];
        }

        self::assertSame($expected, $configs[Config::XML_PATH_WELCOME_ENABLED] ?? null);
    }

    /**
     * @return array<string, array{array<string, string>, array<string, string>, ?string}>
     */
    public static function welcomeAtAWebsiteProvider(): array
    {
        $on = ['subscribe/enableNewsletterSubscriptions' => '1', 'subscribe/workflowId' => '101'];
        $offWithWorkflow = ['subscribe/enableNewsletterSubscriptions' => '0', 'subscribe/workflowId' => '101'];

        return [
            'own off, default on with a workflow' => [
                ['subscribe/enableNewsletterSubscriptions' => '0'], $on, '0',
            ],
            'own on, default off with a workflow' => [
                ['subscribe/enableNewsletterSubscriptions' => '1'], $offWithWorkflow, '1',
            ],
            'own on, no workflow anywhere' => [
                ['subscribe/enableNewsletterSubscriptions' => '1'], [], '0',
            ],
            'own workflow, default on' => [
                ['subscribe/workflowId' => '202'], ['subscribe/enableNewsletterSubscriptions' => '1'], '1',
            ],
            'own workflow 0, default on with a workflow' => [
                ['subscribe/workflowId' => '0'], $on, '0',
            ],
            'nothing of its own: follows the default' => [
                ['sync/enableCronSync' => '1'], $on, null,
            ],
        ];
    }

    /**
     * PRO-4015: a website where 2.8.x had the opt-in on with no Autoresponder
     * ID gets the upgrade notice; one with a workflow, with the opt-in off or
     * with Enable Module = No does not.
     *
     * @dataProvider welcomeWithoutWorkflowProvider
     *
     * @param array<string, string> $website
     * @param array<string, string> $default
     */
    public function testWelcomeWithoutWorkflowIsTheOptInOnWithNoAutoresponderId(
        array $website,
        array $default,
        bool $expected
    ): void {
        self::assertSame($expected, $this->mapper->welcomeWithoutWorkflow($website, $default));
    }

    /**
     * @return array<string, array{array<string, string>, array<string, string>, bool}>
     */
    public static function welcomeWithoutWorkflowProvider(): array
    {
        $onWithoutWorkflow = ['subscribe/enableNewsletterSubscriptions' => '1'];
        $on = ['subscribe/enableNewsletterSubscriptions' => '1', 'subscribe/workflowId' => '101'];

        return [
            'own on, no workflow anywhere' => [$onWithoutWorkflow, [], true],
            'own workflow empty, default on with a workflow' => [['subscribe/workflowId' => ''], $on, true],
            'nothing of its own, default on without a workflow' => [[], $onWithoutWorkflow, true],
            'own Enable Module Yes, default No and on without a workflow' => [
                ['general/enable' => '1'], ['general/enable' => '0'] + $onWithoutWorkflow, true,
            ],
            'own on, default workflow' => [$onWithoutWorkflow, ['subscribe/workflowId' => '101'], false],
            'nothing of its own, default on with a workflow' => [[], $on, false],
            'own off, default on without a workflow' => [
                ['subscribe/enableNewsletterSubscriptions' => '0'], $onWithoutWorkflow, false,
            ],
            'own Enable Module No' => [['general/enable' => '0'] + $onWithoutWorkflow, [], false],
            'nothing of its own, default Enable Module No' => [[], ['general/enable' => '0'] + $onWithoutWorkflow, false],
        ];
    }

    /**
     * PRO-4015: a website where 2.8.x had Enable Abandoned Cart on with no
     * Autoresponder ID gets the upgrade notice; one with a workflow, with it
     * off or with Enable Module = No does not.
     *
     * @dataProvider abandonedCartWithoutWorkflowProvider
     *
     * @param array<string, string> $website
     * @param array<string, string> $default
     */
    public function testAbandonedCartWithoutWorkflowIsTheSwitchOnWithNoAutoresponderId(
        array $website,
        array $default,
        bool $expected
    ): void {
        self::assertSame($expected, $this->mapper->abandonedCartWithoutWorkflow($website, $default));
    }

    /**
     * @return array<string, array{array<string, string>, array<string, string>, bool}>
     */
    public static function abandonedCartWithoutWorkflowProvider(): array
    {
        $onWithoutWorkflow = ['abandoned/enableAbandonedCart' => '1'];
        $on = ['abandoned/enableAbandonedCart' => '1', 'abandoned/autoresponderId' => '77'];

        return [
            'own on, no workflow anywhere' => [$onWithoutWorkflow, [], true],
            'own workflow empty, default on with a workflow' => [['abandoned/autoresponderId' => ''], $on, true],
            'nothing of its own, default on without a workflow' => [[], $onWithoutWorkflow, true],
            'own Enable Module Yes, default No and on without a workflow' => [
                ['general/enable' => '1'], ['general/enable' => '0'] + $onWithoutWorkflow, true,
            ],
            'own on, default workflow' => [$onWithoutWorkflow, ['abandoned/autoresponderId' => '77'], false],
            'nothing of its own, default on with a workflow' => [[], $on, false],
            'own off, default on without a workflow' => [
                ['abandoned/enableAbandonedCart' => '0'], $onWithoutWorkflow, false,
            ],
            'own Enable Module No' => [['general/enable' => '0'] + $onWithoutWorkflow, [], false],
            'nothing of its own, default Enable Module No' => [[], ['general/enable' => '0'] + $onWithoutWorkflow, false],
            'the opt-in on without a workflow is not abandoned cart' => [
                ['subscribe/enableNewsletterSubscriptions' => '1'], [], false,
            ],
        ];
    }

    /**
     * PRO-4013: 2.8.x sent the abandoned-cart reminder at a website when
     * Enable Abandoned Cart and the Autoresponder ID, each the website's own
     * value else the default scope's, were both set.
     *
     * @dataProvider abandonedCartAtAWebsiteProvider
     *
     * @param array<string, string> $website
     * @param array<string, string> $default
     */
    public function testAWebsiteAbandonedCartSwitchIsWhat28xResolvedThere(
        array $website,
        array $default,
        ?string $expected
    ): void {
        $configs = [];
        foreach ($this->mapper->map($website, $default)['configs'] as $config) {
            $configs[$config['path']] = $config['value'];
        }

        self::assertSame($expected, $configs[Config::XML_PATH_ABANDONED_ENABLED] ?? null);
    }

    /**
     * @return array<string, array{array<string, string>, array<string, string>, ?string}>
     */
    public static function abandonedCartAtAWebsiteProvider(): array
    {
        $on = ['abandoned/enableAbandonedCart' => '1', 'abandoned/autoresponderId' => '77'];
        $offWithWorkflow = ['abandoned/enableAbandonedCart' => '0', 'abandoned/autoresponderId' => '77'];

        return [
            'own off, default on with a workflow' => [
                ['abandoned/enableAbandonedCart' => '0'], $on, '0',
            ],
            'own on, default off with a workflow' => [
                ['abandoned/enableAbandonedCart' => '1'], $offWithWorkflow, '1',
            ],
            'own on, no workflow anywhere' => [
                ['abandoned/enableAbandonedCart' => '1'], [], '0',
            ],
            'own workflow, default on' => [
                ['abandoned/autoresponderId' => '88'], ['abandoned/enableAbandonedCart' => '1'], '1',
            ],
            'own "No automation workflow selected", default on with a workflow' => [
                ['abandoned/autoresponderId' => ''], $on, '0',
            ],
            'own delay only: follows the default' => [
                ['abandoned/syncTime' => '1:hour'], $on, null,
            ],
        ];
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

    public function testModuleSwitchValuesAreTheCarriedOverValueElseTheConfigXmlDefault(): void
    {
        self::assertSame(
            [
                Config::XML_PATH_ABANDONED_ENABLED => '1',
                Config::XML_PATH_SYNC_ENABLED => '1',
                Config::XML_PATH_WELCOME_ENABLED => '0',
            ],
            $this->mapper->moduleSwitchValues([
                'general/enable' => '0',
                'abandoned/enableAbandonedCart' => '1',
                'abandoned/autoresponderId' => '77',
            ])
        );
        self::assertSame(LegacyConfigMapper::MODULE_SWITCH_DEFAULTS, $this->mapper->moduleSwitchValues([]));
    }

    public function testModuleSwitchDefaultsMatchConfigXml(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/etc/config.xml');
        self::assertNotFalse($xml);
        foreach (LegacyConfigMapper::MODULE_SWITCH_DEFAULTS as $path => $default) {
            [$section, $group, $field] = explode('/', $path);
            self::assertSame($default, (string)$xml->default->{$section}->{$group}->{$field}, $path);
        }
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
