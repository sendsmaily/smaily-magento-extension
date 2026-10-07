<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\ViewModel;

use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\ViewModel\Adminhtml\AutomationsForm;

class AutomationsFormTest extends TestCase
{
    /**
     * Trigger title/description follow the resolved admin locale, with an
     * `_en` fallback when the localized catalog field is missing or empty.
     *
     * @param array<string, mixed> $trigger
     * @dataProvider localeProvider
     */
    public function testTriggerTitleAndDescriptionAreLocaleAware(
        string $locale,
        array $trigger,
        string $expectedName,
        string $expectedDescription
    ): void {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);

        $client = $this->createMock(Client::class);
        $client->method('automationsCatalog')->willReturn(['triggers' => [$trigger]]);
        $client->method('getAutomationsConfig')->willReturn(['configs' => []]);

        $localeResolver = $this->createMock(LocaleResolver::class);
        $localeResolver->method('getLocale')->willReturn($locale);

        $form = new AutomationsForm(
            $settings,
            $client,
            $this->createMock(SmailyClientProvider::class),
            $localeResolver
        );

        $rows = $form->getRows();

        self::assertCount(1, $rows);
        self::assertSame($expectedName, $rows[0]['name']);
        self::assertSame($expectedDescription, $rows[0]['description']);
    }

    /**
     * PRO-2465: while Campaign Intelligence refuses the account (contract §2
     * `403 tenant_inactive`, remembered by PRO-2451), the Automations tab
     * does not ask the engine for its automations; the page explains the
     * refusal as Settings > Intelligence does, instead of a failed load.
     */
    public function testARefusedAccountIsNotAskedForItsAutomations(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);
        $settings->method('isRefused')->willReturn(true);

        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('automationsCatalog');
        $client->expects(self::never())->method('getAutomationsConfig');

        $form = new AutomationsForm(
            $settings,
            $client,
            $this->createMock(SmailyClientProvider::class),
            $this->createMock(LocaleResolver::class)
        );

        self::assertTrue($form->isEngineConnected());
        self::assertTrue($form->isEngineRefused());
        self::assertNull($form->getLoadError());
        self::assertSame([], $form->getRows());
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string, string}>
     */
    public static function localeProvider(): array
    {
        $bilingual = [
            'key' => 'winback_risk',
            'name_en' => 'Win-back',
            'name_et' => 'Tagasivõitmine',
            'description_en' => 'Re-engage lapsing customers.',
            'description_et' => 'Taasaktiveeri hääbuvad kliendid.',
        ];

        return [
            'et locale uses the _et field' => [
                'et_EE',
                $bilingual,
                'Tagasivõitmine',
                'Taasaktiveeri hääbuvad kliendid.',
            ],
            'et locale but _et missing falls back to _en' => [
                'et_EE',
                [
                    'key' => 'winback_risk',
                    'name_en' => 'Win-back',
                    'description_en' => 'Re-engage lapsing customers.',
                ],
                'Win-back',
                'Re-engage lapsing customers.',
            ],
            'et locale but _et blank falls back to _en' => [
                'et_EE',
                [
                    'key' => 'winback_risk',
                    'name_en' => 'Win-back',
                    'name_et' => '   ',
                    'description_en' => 'Re-engage lapsing customers.',
                    'description_et' => '',
                ],
                'Win-back',
                'Re-engage lapsing customers.',
            ],
            'en locale uses the _en field' => [
                'en_US',
                $bilingual,
                'Win-back',
                'Re-engage lapsing customers.',
            ],
            'unknown locale falls back to _en' => [
                'zz_ZZ',
                $bilingual,
                'Win-back',
                'Re-engage lapsing customers.',
            ],
        ];
    }
}
