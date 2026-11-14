<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Plugin\Adminhtml;

use Magento\Config\Model\Config as SystemConfig;
use Smaily\Connect\Model\Report\SetupChange;

/**
 * A save of the module's section under Stores > Configuration is a save of
 * the setup for the installation report (CON-234): a removed Smaily account
 * is reported at once, and a `setup_saved` snapshot is scheduled.
 */
class ReportConfigSave
{
    /** @var array{accounts: string[], main: ?\Smaily\Connect\Model\Report\Credentials}|null before the save under way */
    private ?array $before = null;

    public function __construct(
        private readonly SetupChange $setupChange
    ) {
    }

    public function beforeSave(SystemConfig $subject): void
    {
        $this->before = $subject->getSection() === 'smaily_connect' ? $this->setupChange->before() : null;
    }

    /**
     * @param SystemConfig $subject
     * @param SystemConfig $result
     * @return SystemConfig
     */
    public function afterSave(SystemConfig $subject, SystemConfig $result): SystemConfig
    {
        if ($this->before !== null) {
            $before = $this->before;
            $this->before = null;
            $this->setupChange->after($before);
        }

        return $result;
    }
}
