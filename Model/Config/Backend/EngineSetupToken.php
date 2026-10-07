<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Smaily\Connect\Model\Backfill\CatalogImportOnConnect;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Settings;

/**
 * Exchanges a pasted setup token/URL on config save. The one-time token is
 * consumed immediately and never persisted — only the exchange result
 * (tenant credentials) is stored via Settings. A successful exchange starts
 * the catalog import and says so (PRO-3741).
 */
class EngineSetupToken extends Value
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly Client $client,
        private readonly Settings $settings,
        private readonly ManagerInterface $messageManager,
        private readonly CatalogImportOnConnect $catalogImport,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @inheritDoc
     */
    public function beforeSave()
    {
        $token = trim((string)$this->getValue());
        // Never persist the token itself.
        $this->setValue('');

        if ($token !== '') {
            try {
                $response = $this->client->setupExchange($token);
                $this->settings->storeExchange($response);
                $this->messageManager->addSuccessMessage(
                    __(
                        'Campaign Intelligence connected: %1 (engine %2).',
                        (string)($response['tenant_name'] ?? $response['tenant_id'] ?? 'tenant'),
                        (string)($response['engine_version'] ?? '?')
                    )->render()
                );
                if ($this->catalogImport->start()) {
                    $this->messageManager->addNoticeMessage(
                        __('The catalog import has started: your whole catalog goes to Campaign Intelligence once, in the background, from the next cron run (usually within a minute). To hold it back, press Cancel import on the Catalog card under Marketing > Smaily Connect > Settings > Intelligence before then: nothing is sent, and you can start the import later. Canceled after it began sending, the products already queued for sending still reach Campaign Intelligence and the rest are not sent.')->render()
                    );
                }
            } catch (EngineException $exception) {
                throw new ValidatorException(
                    __('Campaign Intelligence setup failed: %1', $exception->getMessage())
                );
            }
        }

        return parent::beforeSave();
    }
}
