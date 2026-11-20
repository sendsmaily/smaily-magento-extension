<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client;

use Smaily\Connect\Model\Backfill\ErrorLocation;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Engine\Exception\EngineException;

/**
 * What a queue row's error, the Log and the log file keep of a failure
 * (CON-357): never text that Smaily, Campaign Intelligence or the database
 * wrote, which can name a customer. A Smaily or engine client failure keeps
 * the client's own message, which names the HTTP status and the error code
 * only; any other error keeps its type, its code and the place it was
 * thrown — as the WooCommerce plugin records a failure.
 */
class FailureText
{
    public static function of(\Throwable $failure): string
    {
        if ($failure instanceof SmailyClientException) {
            return $failure->getSourceMessage();
        }
        if ($failure instanceof EngineException) {
            return $failure->getMessage();
        }

        return sprintf('%s (code %s)', ErrorLocation::of($failure), (string)$failure->getCode());
    }
}
