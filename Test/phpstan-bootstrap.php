<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

// PHPStan loads this file in its main process and in every parallel worker
// (phpstan.neon.dist, bootstrapFiles). The analysis needs more than PHP's
// default 128M, so `vendor/bin/phpstan analyse` raises the limit itself; a
// higher limit, or none, from php.ini or --memory-limit stays as it is.
$smailyPhpstanLimit = (string) ini_get('memory_limit');
$smailyPhpstanBytes = (int) $smailyPhpstanLimit
    * (['k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3][strtolower(substr($smailyPhpstanLimit, -1))] ?? 1);
if ($smailyPhpstanLimit !== '-1' && $smailyPhpstanBytes < 1024 ** 3) {
    ini_set('memory_limit', '1G'); // phpcs:ignore Magento2.Functions.DiscouragedFunction
}
