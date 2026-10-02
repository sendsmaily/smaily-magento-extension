<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client\Exception;

/**
 * Smaily refused the request because the account's package does not include
 * API access: HTTP 403 with Smaily code 227, "A paid package is required"
 * (https://smaily.com/help/api/general/response-codes/). Smaily answers so
 * before it checks the credentials, so this is not an AuthenticationException:
 * the credentials are neither right nor wrong as far as anyone can tell.
 */
class PlanBlockedException extends TransportException
{
    public const SMAILY_CODE = 227;
}
