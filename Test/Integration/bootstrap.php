<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

// Magento normalizes the PHP timezone to UTC at bootstrap; the harness
// relies on the same invariant (date() calls inside Stdlib DateTime).
date_default_timezone_set('UTC');

// Fail fast (with connection instructions) before any test runs.
\Smaily\Connect\Test\Integration\Support\TestEnvironment::instance();
