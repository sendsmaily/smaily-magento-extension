<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client\Exception;

/**
 * Invalid or missing Smaily API credentials (HTTP 401/403).
 */
class AuthenticationException extends RequestRefusedException
{
}
