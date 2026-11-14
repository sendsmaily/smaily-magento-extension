<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Plugin\Adminhtml;

use Magento\Config\Model\Config as SystemConfig;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Report\SetupChange;
use Smaily\Connect\Plugin\Adminhtml\ReportConfigSave;

/**
 * CON-234: a save of the module's section under Stores > Configuration is
 * a save of the setup for the installation report; other sections are not.
 */
class ReportConfigSaveTest extends TestCase
{
    public function testTheModulesSectionIsReportedAroundItsSave(): void
    {
        $before = ['accounts' => ['marketing|example'], 'main' => null];
        $setupChange = $this->createMock(SetupChange::class);
        $setupChange->expects(self::once())->method('before')->willReturn($before);
        $setupChange->expects(self::once())->method('after')->with($before, true);
        $plugin = new ReportConfigSave($setupChange);
        $config = $this->config('smaily_connect');

        $plugin->beforeSave($config);
        self::assertSame($config, $plugin->afterSave($config, $config));
    }

    public function testAnotherSectionIsNot(): void
    {
        $setupChange = $this->createMock(SetupChange::class);
        $setupChange->expects(self::never())->method('before');
        $setupChange->expects(self::never())->method('after');
        $plugin = new ReportConfigSave($setupChange);
        $config = $this->config('catalog');

        $plugin->beforeSave($config);
        $plugin->afterSave($config, $config);
    }

    private function config(string $section): SystemConfig
    {
        $config = $this->getMockBuilder(SystemConfig::class)->disableOriginalConstructor()
            ->addMethods(['getSection'])->getMock();
        $config->method('getSection')->willReturn($section);

        return $config;
    }
}
