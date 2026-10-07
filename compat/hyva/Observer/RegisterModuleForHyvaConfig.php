<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\SmailyConnect\Observer;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Registers this module for `bin/magento hyva:config:generate` so its
 * templates end up in app/etc/hyva-themes.json and the theme's Tailwind
 * build scans them for utility classes (purge/content inclusion).
 *
 * Canonical pattern from the Hyvä docs ("Registering a module for Tailwind
 * compilation"); the src path must be relative to the Magento base dir.
 */
class RegisterModuleForHyvaConfig implements ObserverInterface
{
    public function __construct(private readonly ComponentRegistrar $componentRegistrar)
    {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $config = $observer->getData('config');
        $extensions = $config->hasData('extensions') ? $config->getData('extensions') : [];

        $path = (string)$this->componentRegistrar->getPath(ComponentRegistrar::MODULE, 'Hyva_SmailyConnect');
        // BP is Magento's base-dir constant; defined() guard keeps static
        // analysis outside a Magento bootstrap happy.
        $basePath = defined('BP') ? (string)constant('BP') : '';
        if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
            $path = substr($path, strlen($basePath) + 1);
        }

        $extensions[] = ['src' => $path];
        $config->setData('extensions', $extensions);
    }
}
