<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Log;

use Smaily\Connect\Cron\AbandonedCart;
use Smaily\Connect\Model\Queue\Handler\AutomationHandler;
use Smaily\Connect\Model\Queue\Handler\ContactSyncHandler;
use Smaily\Connect\Model\Queue\Handler\IdentityMergeHandler;
use Smaily\Connect\Model\Queue\Handler\ProfilingConsentHandler;

/**
 * What a failed row says to the merchant (PRO-2454).
 *
 * A terminal refusal is stored as `permanent_http_<code>: <server message>`
 * (Model\Queue\RetryPolicy) — the classification is ours, the sentence after
 * it is Smaily's own, except where the client words a refusal itself
 * (rejected credentials, PRO-2508). The merchant is shown that sentence, redacted
 * exactly like the payload beside it; the classification stays for the
 * Details drawer, where the technical detail belongs. A retryable failure
 * carries no prefix and is shown as it is.
 *
 * The client's own messages are stored as English source text, whatever the
 * store's language (PRO-3628), and are translated here, in the admin's
 * language, when the row is shown. A row stored in another language before
 * that, or a message that is not one of ours, is shown as stored.
 */
class FailureMessage
{
    /**
     * The client messages a queue row can store, as written in __() in
     * Model\Client\SmailyClient, SmailyClientProvider and
     * Exception\InvalidSubdomainException. A message added there that the
     * queue can store is added here too, or it is shown in English. The
     * reason a handler closes a row without sending it is stored the same
     * way, and is read here too.
     */
    public const TRANSLATED = [
        'Smaily refused the request because this account\'s package does not include API access.'
            . ' Upgrade the package in Smaily to connect — until then the credentials cannot be checked at all.',
        'Smaily API credentials were rejected',
        'Smaily API request failed with HTTP %1',
        'Smaily API request failed: %1',
        'Smaily API returned a malformed response body',
        'Smaily API returned code %1: %2',
        'Smaily API credentials are not configured (store scope: %1)',
        'The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.',
        // Not errors: the reasons a row was closed without sending (PRO-3619, PRO-3634, PRO-3693).
        ContactSyncHandler::SKIPPED_NOT_A_CONTACT,
        AutomationHandler::SKIPPED_NO_WORKFLOW,
        ProfilingConsentHandler::SKIPPED_REPLACED,
        IdentityMergeHandler::SKIPPED_OPTED_OUT,
        AbandonedCart::SKIPPED_RECENTLY_REMINDED,
    ];

    /** What RetryPolicy prepends to a refusal it parked on the spot. */
    private const CLASS_PATTERN = '/^(permanent_http_\d+):\s*/';

    public function __construct(
        private readonly PayloadRedactor $redactor
    ) {
    }

    /**
     * The merchant-facing wording of a stored `last_error`: the server's own
     * message where there is one, in the admin's language where it is one of
     * ours, with secrets hidden.
     */
    public function forDisplay(?string $lastError): string
    {
        return $this->redactor->redact(
            $this->translate((string)preg_replace(self::CLASS_PATTERN, '', trim((string)$lastError)))
        );
    }

    /**
     * The internal failure class of a stored `last_error`, or '' when the
     * failure was not classified as permanent.
     */
    public function failureClass(?string $lastError): string
    {
        preg_match(self::CLASS_PATTERN, trim((string)$lastError), $matches);

        return $matches[1] ?? '';
    }

    private function translate(string $message): string
    {
        foreach (self::TRANSLATED as $source) {
            $pattern = '/^' . preg_replace('/%\d+/', '(.*?)', preg_quote($source, '/')) . '$/su';
            if (preg_match($pattern, $message, $matches)) {
                return (string)__($source, ...array_slice($matches, 1));
            }
        }

        return $message;
    }
}
