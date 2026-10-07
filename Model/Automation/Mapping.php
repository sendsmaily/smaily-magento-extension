<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Automation;

use Magento\Framework\Model\AbstractModel;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;

/**
 * A per-language automation workflow mapping row (smaily_automation_mapping).
 */
class Mapping extends AbstractModel
{
    public const LANGUAGE_DEFAULT = 'default';
    public const ACCOUNT_DEFAULT = 'default';

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(MappingResource::class);
    }

    public function getWorkflowId(): int
    {
        return (int)$this->getData('workflow_id');
    }

    /**
     * The Smaily account key ('default' or a language code, multilingual
     * mode A) whose credentials must deliver this row's workflow.
     */
    public function getAccountKey(): string
    {
        $key = (string)$this->getData('account_key');

        return $key === '' ? self::ACCOUNT_DEFAULT : $key;
    }
}
