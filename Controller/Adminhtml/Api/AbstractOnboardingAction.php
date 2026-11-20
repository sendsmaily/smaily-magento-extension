<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Api;

use Magento\Framework\Controller\Result\Json;

/**
 * Base of the Campaign Intelligence banner's endpoints (CON-363): the
 * Settings ACL resource (Smaily_Connect::config, from AbstractJsonAction),
 * a POST with the admin's form key (AbstractJsonAction::formKeyRefusal(),
 * as the "Edit design" endpoints check it) and answers no cache may keep
 * (`Cache-Control: no-store, private`), because the start's answer holds a
 * one-time link.
 */
abstract class AbstractOnboardingAction extends AbstractJsonAction
{
    protected const NO_STORE = 'no-store, private';

    /**
     * @param array<string, mixed> $data
     */
    protected function answer(array $data, int $httpStatus = 200): Json
    {
        return $this->noStore($this->jsonResponse($data, $httpStatus));
    }
}
