<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * The installed module version, read from this package's composer.json —
 * the single place the version number is maintained.
 */
class ModuleVersion
{
    private ?string $version = null;

    public function __construct(
        private readonly ComponentRegistrarInterface $componentRegistrar,
        private readonly ReadFactory $readFactory,
        private readonly Json $serializer
    ) {
    }

    /**
     * Get the module version ("3.0.0-rc8"), or "" when unreadable.
     */
    public function current(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $this->version = '';
        $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, 'Smaily_Connect');
        if ($path !== null) {
            try {
                $directory = $this->readFactory->create($path);
                $composer = $this->serializer->unserialize($directory->readFile('composer.json'));
                if (is_array($composer)) {
                    $this->version = (string)($composer['version'] ?? '');
                }
            } catch (\Exception) {
                // Leave "" — version display/compare degrades gracefully.
            }
        }

        return $this->version;
    }

    /**
     * Major part of a version string ("3.1.0-beta2" -> 3; unknown -> 0).
     */
    public function major(string $version): int
    {
        return (int)strtok($version, '.');
    }
}
