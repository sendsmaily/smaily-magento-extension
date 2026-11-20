<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\Phrase;
use Smaily\Connect\Cron\AbandonedCart;
use Smaily\Connect\Model\Config;

/**
 * Refuses an abandoned-cart cutoff with which no reminder could be sent
 * (CON-368): one longer than AbandonedCart::MAX_CUTOFF_MINUTES, in
 * Stores > Configuration and `config:set`. The Settings and setup save
 * (WizardStepSaver) refuses it with the same message.
 */
class AbandonedCutoff extends Value
{
    /**
     * The refusal, naming the cutoffs that work.
     */
    public static function refusal(): Phrase
    {
        return __(
            'The abandoned-cart wait must be from %1 to %2 minutes. A longer wait would send no reminder.',
            Config::MIN_ABANDONED_CUTOFF_MINUTES,
            AbandonedCart::MAX_CUTOFF_MINUTES
        );
    }

    /**
     * @inheritDoc
     */
    public function beforeSave()
    {
        if ((int)$this->getValue() > AbandonedCart::MAX_CUTOFF_MINUTES) {
            throw new ValidatorException(self::refusal());
        }

        return parent::beforeSave();
    }
}
