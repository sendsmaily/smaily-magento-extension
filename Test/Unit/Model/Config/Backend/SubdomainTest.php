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
use Smaily\Connect\Model\Config\Backend\Subdomain;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * PRO-3575: Stores > Configuration and `config:set` keep only a plain Smaily
 * subdomain.
 */
class SubdomainTest extends TestCase
{
    public function testAPastedSmailyUrlIsSavedAsItsSubdomain(): void
    {
        $model = $this->model('https://my-store.sendsmaily.net/');

        $model->beforeSave();

        self::assertSame('my-store', $model->getValue());
    }

    public function testAnEmptyValueIsSavedEmpty(): void
    {
        $model = $this->model('');

        $model->beforeSave();

        self::assertSame('', $model->getValue());
    }

    public function testASubdomainThatIsNotPlainIsRefused(): void
    {
        $model = $this->model('engine.example#');

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage(
            'The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.'
        );
        $model->beforeSave();
    }

    private function model(string $value): Subdomain
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(EventManager::class));

        $model = new Subdomain(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            new SubdomainNormalizer()
        );
        $model->setValue($value);

        return $model;
    }
}
