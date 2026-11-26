<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\ViewModel\Adminhtml\AutomationsForm;

/**
 * A Campaign Intelligence email's test mode and test send on its card
 * (CON-458, as the WooCommerce plugin's CON-455): the test email (contract
 * §19), the real-sends switch (§20) and the state the card shows (§12
 * `setup[]`), in the admin's words.
 *
 * Test mode on is every state but `live`. Turning it off switches real
 * sends on (§20 `live: true`, after the admin confirmed the dialog), on
 * again switches them off (`live: false`). After every call the engine
 * answered, the card's state is read again from §12. A refusal is answered
 * in plain words and never retried: a second test send can send a second
 * email (§19).
 */
class EmailTestMode
{
    /** §12 `notice`: the email starts once its Smaily workflow is active. */
    private const NOTICE_SENDER = 'starts_when_sender_verified';

    /** §12 blocker after an automatic or operator stop, until Smaily reviews it. */
    private const STOPPED = 'stopped_needs_review';

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * The card's state from one §12 `setup[]` item: whether test mode is on,
     * the pill's mode (`off`, `test`, `active`), the state line and the
     * notice ('' when there is none).
     *
     * @param array<string, mixed> $item
     * @return array{test_mode: bool, mode: string, status: string, notice: string}
     */
    public static function describe(array $item): array
    {
        $state = (string)($item['setup_state'] ?? '');
        $live = $state === 'live';
        $stopped = $state === 'stopped'
            || in_array(self::STOPPED, (array)($item['go_live_blockers'] ?? []), true);
        $limit = $item['daily_limit'] ?? null;

        if ($live) {
            $status = is_int($limit)
                ? __('Sending to real customers · up to %1 emails today', $limit)
                : __('Sending to real customers');
        } elseif ($stopped) {
            $status = self::stoppedText();
        } else {
            $status = '';
        }

        return [
            'test_mode' => !$live,
            'mode' => $live ? 'active' : (in_array($state, ['test', 'stopped'], true) ? 'test' : 'off'),
            'status' => (string)$status,
            'notice' => ($item['notice'] ?? null) === self::NOTICE_SENDER
                ? (string)__('Starts when your sender domain is verified in Smaily')
                : '',
        ];
    }

    /**
     * Each catalogue email's card state from a §12 answer, by key. An
     * engine without `setup[]` (before contract v1.15.0) gives none.
     *
     * @param array<string, mixed> $config
     * @return array<string, array{test_mode: bool, mode: string, status: string, notice: string}>
     */
    public static function states(array $config): array
    {
        $states = [];
        foreach ((array)($config['setup'] ?? []) as $item) {
            if (is_array($item) && isset($item['trigger_key'])) {
                $states[(string)$item['trigger_key']] = self::describe($item);
            }
        }

        return $states;
    }

    /**
     * Send one test email of `$key` to `$email` (§19).
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function testSend(string $key, string $email): array
    {
        try {
            $this->client->automationTestSend($key, $email);
        } catch (EngineException $exception) {
            return $this->failure($key, $exception, true);
        }

        return $this->success($key, (string)__('Smaily accepted the test email. Check the inbox.'));
    }

    /**
     * Turn test mode off (real sends on, §20 `live: true`) or on again
     * (`live: false`). `$switchedBy` is the admin's user id.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function switchTestMode(string $key, bool $testMode, string $switchedBy): array
    {
        try {
            $this->client->automationRealSends($key, !$testMode, $switchedBy);
        } catch (EngineException $exception) {
            return $this->failure($key, $exception, false);
        }

        return $this->success($key, '');
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function success(string $key, string $message): array
    {
        return ['status' => 200, 'body' => ['ok' => true, 'message' => $message, 'state' => $this->state($key)]];
    }

    /**
     * An engine refusal or failure in the admin's words, with the card's
     * state read again when the engine answered the request.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function failure(string $key, EngineException $exception, bool $testSend): array
    {
        $status = (int)$exception->getCode();
        $details = [];
        $error = '';
        if ($exception instanceof EngineRequestException) {
            $error = (string)($exception->getErrorBody()['error'] ?? '');
            $details = (array)($exception->getErrorBody()['details'] ?? []);
        }
        $reasons = [];

        if ($status === 409 && $error === 'not_ready') {
            $message = __('The test email cannot be sent yet:');
            $reasons = $this->reasons((array)($details['reasons'] ?? []));
        } elseif ($status === 409 && $error === 'sender_refused') {
            $from = (string)($details['from_address'] ?? '');
            $message = $from !== ''
                ? __('Smaily refused to send from %1. Check your sender domain in Smaily.', $from)
                : __('Smaily refused to send from your sender address. Check your sender domain in Smaily.');
        } elseif ($status === 409 && $error === 'go_live_blocked') {
            $message = __('Test mode stays on:');
            $reasons = $this->reasons((array)($details['blockers'] ?? []));
        } elseif ($status === 422) {
            $message = $testSend
                ? __('Enter one valid email address.')
                : __('Test mode could not be turned off. Reload the page and try again.');
        } elseif ($status === 404) {
            $message = __('Campaign Intelligence does not offer this for this email yet.');
        } elseif ($status === 401) {
            $message = __(
                'Campaign Intelligence did not accept this store\'s connection.'
                . ' Connect again under Settings > Intelligence.'
            );
        } elseif ($status === 403) {
            $message = AutomationsForm::refusedMessage();
        } elseif ($status === 429) {
            $message = $testSend
                ? __('Too many test emails were sent in the last hour. Try again later.')
                : __('Test mode was switched too often. Try again in a minute.');
        } elseif ($status === 502 && $testSend) {
            $message = __('Smaily did not answer, so nothing was sent. Press Send test email to try again.');
        } else {
            $message = __('Campaign Intelligence did not answer. Try again in a minute.');
        }

        $answered = $exception instanceof EngineRequestException && !in_array($status, [401, 403], true);

        return [
            'status' => $status >= 400 && $status < 600 ? $status : 502,
            'body' => [
                'ok' => false,
                'error' => $error !== '' ? $error : 'http_' . $status,
                'message' => (string)$message,
                'reasons' => $reasons,
                'state' => $answered ? $this->state($key) : null,
            ],
        ];
    }

    /**
     * A §19 `not_ready` reason or a §20 blocker in plain words; an unknown
     * value reads "Setup is not complete", once.
     *
     * @param array<int|string, mixed> $values
     * @return string[]
     */
    private function reasons(array $values): array
    {
        $texts = [];
        foreach ($values as $value) {
            $text = match ((string)$value) {
                'no_workflow' => __('No Smaily workflow sends this email yet'),
                'workflow_not_active' => __(
                    'The email\'s Smaily workflow is not active yet, for example because your sender domain'
                    . ' is not verified'
                ),
                'no_design' => __('The email has no design yet'),
                'design_check_failed' => __('The email\'s design check failed — open the design to fix it'),
                'missing_subject' => __('The email has no subject'),
                'no_sender' => __('The email has no sender address'),
                'no_products' => __('No customer has recommendations for this email yet'),
                self::STOPPED => self::stoppedText(),
                'operator_switch_required' => __('Only Smaily can turn off test mode for this email'),
                default => __('Setup is not complete'),
            };
            $texts[] = (string)$text;
        }

        return array_values(array_unique($texts));
    }

    /**
     * The email's card state as §12 reads now, or null when the read fails
     * or names no such email.
     *
     * @return array{test_mode: bool, mode: string, status: string, notice: string}|null
     */
    private function state(string $key): ?array
    {
        try {
            return self::states($this->client->getAutomationsConfig())[$key] ?? null;
        } catch (EngineException) {
            return null;
        }
    }

    private static function stoppedText(): string
    {
        return (string)__('Smaily reviews this stop before the email can send again');
    }
}
