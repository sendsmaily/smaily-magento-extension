<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client\Exception;

use Magento\Framework\Phrase;
use Magento\Framework\Phrase\Renderer\Placeholder;

/**
 * Base exception for all Smaily marketing API client failures.
 *
 * Built from a Phrase, the exception holds the message twice: getMessage()
 * is translated where it was thrown, for an admin page that shows it at
 * once; getSourceMessage() is the English source text with its values
 * filled in. The queue stores the source text and the admin translates it
 * when it shows the row (Model\Log\FailureMessage), so a delivery error
 * reads in the admin's language, not in the language of the store whose
 * cron run sent the row.
 */
class SmailyClientException extends \RuntimeException
{
    private readonly string $sourceMessage;

    public function __construct(string|Phrase $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $this->sourceMessage = $message instanceof Phrase
            ? (string)(new Placeholder())->render([$message->getText()], $message->getArguments())
            : $message;
        parent::__construct((string)$message, $code, $previous);
    }

    /**
     * The message as English source text, never translated: what the queue
     * stores and the log file records.
     */
    public function getSourceMessage(): string
    {
        return $this->sourceMessage;
    }
}
