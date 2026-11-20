<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Api;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Smaily\Connect\Model\Engine\EmailDesigns;
use Smaily\Connect\Model\Engine\Exception\EngineException;

/**
 * POST (form key) {email_key, return_url?, display?} -> {url}
 *
 * A one-time link to one email's design page (contract §22, CON-257),
 * minted when the admin presses "Edit design" or "Open in a new tab": one
 * click, one call. The page shows it in a frame over the admin
 * (`display: 'frame'`, a §22 frame link naming the admin's origin when it
 * is not the shop's, CON-366), or in a new tab (any other `display`). The
 * link is never stored, logged or cached: the answer carries
 * Cache-Control: no-store, and nothing here writes it anywhere.
 *
 * An engine email (a §11 catalogue key) opens its setup page on the
 * engine, where its sending is set up too, so it also needs the
 * Intelligence Automations resource that saving those automations needs.
 *
 * `return_url` goes to the engine only on the host of the site URL the
 * extension gives the engine (§17); `language` is the admin's own.
 */
class DesignLink extends AbstractEmailDesignAction implements HttpPostActionInterface
{
    public const ENGINE_EMAIL_RESOURCE = 'Smaily_Connect::automations';

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        EmailDesigns $emailDesigns,
        private readonly ResolverInterface $localeResolver
    ) {
        parent::__construct($context, $jsonFactory, $serializer, $emailDesigns);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $refusal = $this->refusal();
        if ($refusal !== null) {
            return $refusal;
        }

        $body = $this->requestBody();
        $emailKey = is_string($body['email_key'] ?? null) ? $body['email_key'] : '';
        if (!EmailDesigns::isEmailKey($emailKey)) {
            return $this->failure(
                404,
                'unknown_email',
                (string)__('Campaign Intelligence offers no design for this email.')
            );
        }
        if (EmailDesigns::isEngineEmail($emailKey)
            && !$this->_authorization->isAllowed(self::ENGINE_EMAIL_RESOURCE)) {
            return $this->failure(
                403,
                'forbidden',
                (string)__('You are not allowed to edit the Campaign Intelligence automations.')
            );
        }
        $returnUrl = is_string($body['return_url'] ?? null) ? $body['return_url'] : '';
        $language = strtolower((string)strtok((string)$this->localeResolver->getLocale(), '_'));
        /** @var HttpRequest $request An admin request is an HTTP request. */
        $request = $this->getRequest();

        try {
            $url = $this->emailDesigns->designLink(
                $emailKey,
                $returnUrl,
                $language,
                ($body['display'] ?? '') === 'frame',
                EmailDesigns::requestOrigin($request)
            );
        } catch (EngineException $exception) {
            return $this->engineFailure($exception);
        }

        return $this->noStore($this->jsonResponse(['url' => $url]));
    }
}
