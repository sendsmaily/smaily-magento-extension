<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Privacy;

/**
 * What an erased field carries after an Art. 17 erasure (PRO-2452): a fixed
 * non-address string, so nothing downstream can read a recipient back out of
 * a row the subject asked us to forget.
 *
 * It lives on its own so the two queues' `retry()` can refuse a row carrying
 * it without depending on the anonymiser that writes it.
 */
class Erasure
{
    public const PLACEHOLDER = '[erased]';
}
