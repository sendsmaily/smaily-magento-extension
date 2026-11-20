<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Api;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\EmailDesigns;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Settings;

/**
 * POST (form key, no body) -> 200 {url} | 4xx/5xx {error, message[, console_url]}
 *
 * "Open Campaign Intelligence" on Settings > Intelligence (contract §24,
 * CON-230, as the WooCommerce plugin's CON-228 ConsoleEntryEndpoint): the
 * server checks the admin's session and the Settings ACL resource
 * (AbstractJsonAction) and the form key, and asks the engine — with the
 * API key of the connection the tab shows, the installation-wide one until
 * separate stores get their own (CON-237) — for a one-time link with the
 * signed-in admin's own email. The admin's browser opens it at once in the
 * tab the click opened. One press is one engine call; the link and the
 * email are never stored, logged or cached here, and every answer carries
 * `Cache-Control: no-store, private`.
 *
 * A refusal names the engine's status only, never its message (CON-357):
 * 404 `not_available` with the console's sign-in address (console entry is
 * switched off, or the connection uses the store's legacy key: the page
 * hides the button), 401 `api_key_rejected` (connect again), 403
 * `tenant_inactive`, 429 `rate_limit_exceeded`, anything else
 * `engine_unavailable`.
 */
class ConsoleEntry extends AbstractJsonAction implements HttpPostActionInterface
{
    protected const NO_STORE = 'no-store, private';

    /** §24 `email` limit. */
    private const EMAIL_MAX = 254;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        private readonly Connections $connections
    ) {
        parent::__construct($context, $jsonFactory, $serializer);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $refusal = $this->formKeyRefusal(
            (string)__('Your admin session has expired. Reload the page and try again.')
        );
        if ($refusal !== null) {
            return $refusal;
        }

        $connection = $this->connections->primary();
        $settings = $connection->settings();
        if ($settings->isRefused()) {
            return $this->failure(403, 'tenant_inactive', $this->inactiveMessage());
        }
        if (!$settings->isSendingAllowed()) {
            return $this->failure(503, 'not_available', (string)__('Campaign Intelligence is not connected.'));
        }
        $email = $this->adminEmail();
        if ($email === '') {
            return $this->failure(
                400,
                'no_admin_email',
                (string)__(
                    'Your Magento admin user has no valid email address.'
                    . ' Add one under Account Setting and try again.'
                )
            );
        }

        try {
            $link = $this->connections->client($connection)->consoleEntry($email);
        } catch (EngineException $exception) {
            return $this->engineFailure($exception, $settings);
        }

        $url = is_string($link['url'] ?? null) ? $link['url'] : '';
        if (!str_starts_with($url, 'https://')) {
            return $this->failure(502, 'invalid_engine_response', $this->retryMessage());
        }

        return $this->noStore($this->jsonResponse(['url' => $url]));
    }

    /** The signed-in admin's own email — never a customer's — or ''. */
    private function adminEmail(): string
    {
        // A DataObject holding `email`; Magento_User is not a dependency of this module.
        $user = $this->_auth->getUser();
        $email = $user instanceof DataObject ? trim((string)$user->getData('email')) : '';

        return strlen($email) <= self::EMAIL_MAX && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? $email
            : '';
    }

    /**
     * An engine failure in the admin's words, by its status only.
     */
    private function engineFailure(EngineException $exception, Settings $settings): Json
    {
        $status = (int)$exception->getCode();
        $error = $exception instanceof EngineRequestException
            ? (string)($exception->getErrorBody()['error'] ?? '')
            : '';

        if ($status === 404) {
            $origin = EmailDesigns::origin($settings->getEngineBaseUrl()) ?: Client::DEFAULT_SETUP_BASE_URL;

            return $this->noStore($this->jsonResponse([
                'error' => 'not_available',
                'message' => (string)__(
                    'Opening Campaign Intelligence from the extension is not available yet.'
                    . ' Open the console and sign in there:'
                ),
                'console_url' => $origin . '/login',
            ], 404));
        }
        if ($status === 401) {
            return $this->failure(
                502,
                'api_key_rejected',
                (string)__(
                    'Campaign Intelligence did not accept this store\'s connection.'
                    . ' Connect again under Settings > Intelligence.'
                )
            );
        }
        if ($status === 403 && $error === 'tenant_inactive') {
            return $this->failure(403, 'tenant_inactive', $this->inactiveMessage());
        }
        if ($status === 429) {
            return $this->failure(429, 'rate_limit_exceeded', (string)__('Too many attempts. Try again in a minute.'));
        }

        return $this->failure(502, 'engine_unavailable', $this->retryMessage());
    }

    private function inactiveMessage(): string
    {
        return (string)__('This store is no longer active in Campaign Intelligence.');
    }

    private function retryMessage(): string
    {
        return (string)__('Campaign Intelligence could not be opened. Try again in a minute.');
    }

    private function failure(int $status, string $error, string $message): Json
    {
        return $this->noStore($this->jsonResponse(['error' => $error, 'message' => $message], $status));
    }
}
