<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client\Exception;

/**
 * The HTTP status of a failed Smaily request, which the exception carries as
 * its code (0 when no answer arrived). A trait rather than a method of
 * SmailyClientException: an ApiException's code is Smaily's envelope code,
 * not an HTTP status.
 */
trait HttpStatusCode
{
    /**
     * HTTP status code of the failed response, 0 for pure network failures.
     */
    public function getHttpStatus(): int
    {
        return $this->getCode();
    }
}
