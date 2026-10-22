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
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * POST {step: connect|subscribers|automations|intelligence|finish, data: {...}}
 * -> {saved: bool, errors: [{field, message}], verified?: bool, accountName?: string,
 *     storefrontUrlChanged?: bool, transactionalVerified?: bool, returnedToStoreWide?: true,
 *     ownAccountStarted?: true}
 *
 * A Settings save that returned the selected website to the store-wide
 * values of a tab says so (CON-179), so the page reloads to show them; so
 * does a connection save that turned the website's own Smaily account on,
 * whose automations then start from no workflow (CON-190).
 *
 * The finish step also answers whether Smaily accepted the saved
 * credentials at the last check (PRO-3560), for the Overview step's copy.
 * A connection save answers whether Smaily accepted the credentials it
 * just saved, and the account they belong to, so the Connection status
 * changes without a reload (PRO-3570), and whether it changed the
 * storefront address, so the result can ask for the catalog import again
 * (PRO-3660). A connection save that carried the transactional-email
 * account (Settings > Connection, "Send transactional emails" on) answers
 * whether Smaily accepted it, so the Automations tab shows its triggers
 * without a reload (PRO-4062).
 *
 * Persists one wizard step. All writes go to the same system config paths
 * the Stores > Configuration page edits — the wizard is an onboarding view
 * over the exact same single source of truth.
 */
class SaveStep extends AbstractJsonAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        private readonly WizardStepSaver $stepSaver,
        private readonly VerifiedCredentials $verifiedCredentials,
        private readonly WebsiteContext $websiteContext,
        private readonly SubdomainNormalizer $normalizer
    ) {
        parent::__construct($context, $jsonFactory, $serializer);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $body = $this->requestBody();
        $step = (string)($body['step'] ?? '');
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        $errors = $this->stepSaver->save($step, $data);

        $response = [
            'saved' => $errors === [],
            'errors' => $errors,
        ];
        if ($this->stepSaver->isReturnedToStoreWide()) {
            $response['returnedToStoreWide'] = true;
        }
        if ($this->stepSaver->isOwnAccountStarted()) {
            $response['ownAccountStarted'] = true;
        }
        if ($step === 'connect' && $errors === []) {
            $response['verified'] = $this->stepSaver->isConnectionAccepted();
            $response['accountName'] = $this->normalizer->normalize((string)($data['subdomain'] ?? ''));
            $response['storefrontUrlChanged'] = $this->stepSaver->isStorefrontUrlChanged();
            if (is_array($data['transactional'] ?? null) && !empty($data['transactional']['enabled'])) {
                $response['transactionalVerified'] = $this->stepSaver->isTransactionalAccepted();
            }
        }
        if ($step === 'finish') {
            $response['verified'] = $this->verifiedCredentials->isWebsiteVerified(
                $this->websiteContext->getWebsiteId()
            );
        }

        return $this->jsonResponse($response);
    }
}
