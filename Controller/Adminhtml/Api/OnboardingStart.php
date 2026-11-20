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
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\DataObject;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Smaily\Connect\Model\Engine\EmailDesigns;
use Smaily\Connect\Model\Engine\IntelligenceStart;

/**
 * POST (form key) {display: 'frame' | 'window'}
 *   -> 200 {start_url, display, engine_origin}
 *   |  4xx/5xx {error[, console_url]}
 *
 * "Try Intelligence free" (contract §21, CON-363): the server asks the
 * engine for a one-time start link with the main Smaily account's API
 * login, the signed-in admin's email and the admin's language. The admin's
 * browser opens the link at once; it is never stored or logged, and the
 * answer carries `Cache-Control: no-store, private`. A refusal names the
 * engine's code only (IntelligenceStart), never its message.
 */
class OnboardingStart extends AbstractOnboardingAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        FormKeyValidator $formKeyValidator,
        private readonly IntelligenceStart $intelligenceStart,
        private readonly ResolverInterface $localeResolver
    ) {
        parent::__construct($context, $jsonFactory, $serializer, $formKeyValidator);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $refusal = $this->formKeyRefusal();
        if ($refusal !== null) {
            return $refusal;
        }

        $request = $this->getRequest();
        $adminOrigin = $request instanceof HttpRequest
            ? EmailDesigns::origin($request->getScheme() . '://' . $request->getHttpHost(false))
            : '';
        // The signed-in admin user (a DataObject holding `email`; Magento_User
        // is not a dependency of this module).
        $user = $this->_auth->getUser();

        ['status' => $status, 'body' => $body] = $this->intelligenceStart->start(
            ($this->requestBody()['display'] ?? '') === 'frame',
            $adminOrigin,
            $user instanceof DataObject ? trim((string)$user->getData('email')) : '',
            IntelligenceStart::language((string)$this->localeResolver->getLocale())
        );

        return $this->answer($body, $status);
    }
}
