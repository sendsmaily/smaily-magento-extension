<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Cron\AbandonedCart;
use Smaily\Connect\Model\Config\Backend\AbandonedCutoff;

/**
 * CON-368: Stores > Configuration and `config:set` refuse an abandoned-cart
 * cutoff with which no reminder could be sent, as the Settings save does.
 */
class AbandonedCutoffTest extends TestCase
{
    public function testTheLongestCutoffThatCanSendIsSaved(): void
    {
        $model = $this->model('1435');

        $model->beforeSave();

        self::assertSame('1435', $model->getValue());
    }

    public function testALongerCutoffIsRefusedNamingTheCutoffsThatWork(): void
    {
        $model = $this->model('1436');

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage(
            'The abandoned-cart wait must be from 10 to 1435 minutes. A longer wait would send no reminder.'
        );
        $model->beforeSave();
    }

    /**
     * The field's browser check and its comment name the same longest
     * cutoff as the save.
     */
    public function testTheConfigurationFieldNamesTheSameRange(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../../../etc/adminhtml/system.xml');
        self::assertNotFalse($xml);
        $field = $xml->xpath('//group[@id="automations"]/field[@id="abandoned_cutoff"]')[0] ?? null;
        self::assertNotNull($field);

        $max = AbandonedCart::MAX_CUTOFF_MINUTES;
        self::assertSame('validate-digits digits-range-10-' . $max, (string)$field->validate);
        self::assertStringContainsString('From 10 to ' . $max . ' minutes.', (string)$field->comment);
        self::assertSame(AbandonedCutoff::class, (string)$field->backend_model);
    }

    private function model(string $value): AbandonedCutoff
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(EventManager::class));

        $model = new AbandonedCutoff(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class)
        );
        $model->setValue($value);

        return $model;
    }
}
