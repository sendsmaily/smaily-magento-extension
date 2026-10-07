<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

/**
 * Module version constant. Must be kept in sync with composer.json by the
 * release process.
 */
class ModuleInfo
{
    public const VERSION = '3.0.0-rc10';
    public const USER_AGENT = 'SmailyConnect-MagentoPlugin/' . self::VERSION;
}
