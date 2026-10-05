<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Automations;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Smaily\Connect\Model\Automation\ConfigRowNormalizer;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\ViewModel\Adminhtml\AutomationsForm;

/**
 * Persists engine automation configuration (contract §13). Every row carries
 * all eight keys — no server-side defaults; validation is all-or-nothing.
 * The engine may store a row differently from the request (a row asking for
 * real sends stays in test mode until a Smaily operator switches them on), so
 * the AJAX answer carries each trigger's stored state from §12.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::automations';

    public function __construct(
        Context $context,
        private readonly Client $client,
        private readonly SmailyClientProvider $smailyClientProvider,
        private readonly ConfigRowNormalizer $normalizer,
        private readonly Settings $settings
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect|Json
    {
        $errors = [];
        $savedKeys = [];
        $saved = $this->save($errors, $savedKeys);

        // Embedded config-page block saves via AJAX; plain form posts get a
        // redirect back with flash messages.
        $request = $this->getRequest();
        if ($request instanceof HttpRequest && $request->isXmlHttpRequest()) {
            /** @var Json $json */
            $json = $this->resultFactory->create(ResultFactory::TYPE_JSON);

            $data = ['saved' => $saved, 'errors' => $errors];
            if ($saved) {
                $data['states'] = $this->storedStates($savedKeys);
            }

            return $json->setData($data);
        }

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setRefererOrBaseUrl();
    }

    /**
     * @param string[] $errors collected error strings (also flashed)
     * @param string[] $savedKeys the trigger keys sent to the engine
     */
    private function save(array &$errors, array &$savedKeys): bool
    {
        // A page opened before Campaign Intelligence refused the account
        // (PRO-2451): explain the refusal as Settings > Intelligence does
        // instead of quoting the engine's 403 (PRO-2465).
        if ($this->settings->isRefused()) {
            $errors[] = (string)AutomationsForm::refusedMessage();
            $this->messageManager->addErrorMessage($errors[0]);

            return false;
        }

        // The currently loadable Smaily workflow ids — a saved id absent here
        // was not offered in the dropdown, so the normalizer must not treat an
        // empty single-mode post as a deliberate clear (PRO-1268).
        $availableWorkflowIds = $this->availableWorkflowIds();

        $triggers = (array)$this->getRequest()->getParam('triggers', []);
        $rows = [];
        foreach ($triggers as $key => $data) {
            if (!is_array($data)) {
                continue;
            }
            $rows[] = $this->normalizer->normalize((string)$key, $data, $availableWorkflowIds);
        }

        if (!$rows) {
            $errors[] = (string)__('Nothing to save.');

            return false;
        }

        try {
            $this->client->putAutomationsConfig($rows);
            $savedKeys = array_map(static fn (array $row): string => (string)$row['trigger_key'], $rows);
            $this->messageManager->addSuccessMessage(
                (string)__('%1 automation trigger(s) saved.', count($rows))
            );

            return true;
        } catch (EngineRequestException $exception) {
            foreach ((array)($exception->getErrorBody()['errors'] ?? []) as $error) {
                if (is_array($error)) {
                    $errors[] = sprintf(
                        '%s / %s: %s',
                        (string)($error['trigger_key'] ?? 'row'),
                        (string)($error['field'] ?? ''),
                        (string)($error['message'] ?? 'invalid')
                    );
                }
            }
            $errors[] = (string)__('Nothing was saved: %1', $exception->getMessage());
        } catch (EngineException $exception) {
            $errors[] = (string)__('Saving failed: %1', $exception->getMessage());
        }

        foreach ($errors as $message) {
            $this->messageManager->addErrorMessage($message);
        }

        return false;
    }

    /**
     * Each saved trigger's state as the engine stored it (§12), with the
     * page's fail-closed defaults for a row the read does not return. Null
     * when the read fails — the screen then cannot tell what was stored.
     *
     * @param string[] $keys
     * @return array<string, array{enabled: bool, test_mode: bool}>|null
     */
    private function storedStates(array $keys): ?array
    {
        try {
            $config = $this->client->getAutomationsConfig();
        } catch (EngineException) {
            return null;
        }

        $stored = [];
        foreach ((array)($config['configs'] ?? []) as $row) {
            if (is_array($row) && isset($row['trigger_key'])) {
                $stored[(string)$row['trigger_key']] = $row;
            }
        }

        $states = [];
        foreach ($keys as $key) {
            $states[$key] = [
                'enabled' => (bool)($stored[$key]['enabled'] ?? false),
                'test_mode' => (bool)($stored[$key]['test_mode'] ?? true),
            ];
        }

        return $states;
    }

    /**
     * Workflow ids the Smaily API can currently list, as strings. An empty
     * array (credentials missing or the listing failed) means "unknown" — the
     * normalizer then keeps every saved binding rather than dropping ids it
     * cannot confirm are gone.
     *
     * @return array<int, string>
     */
    private function availableWorkflowIds(): array
    {
        try {
            $workflows = $this->smailyClientProvider->forStore(null)->getAutomationWorkflows();
        } catch (SmailyClientException) {
            return [];
        }

        return array_map(static fn (array $workflow): string => (string)$workflow['id'], $workflows);
    }
}
