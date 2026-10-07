<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Config\Source;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Live list of Smaily automation workflows for admin dropdowns.
 *
 * Resolves credentials for the website scope currently edited in the admin
 * configuration; unreachable/unconfigured API degrades to an empty list so
 * the configuration page always renders.
 */
class Workflows implements OptionSourceInterface
{
    /** @var array<int, array{value: string, label: \Magento\Framework\Phrase|string}>|null */
    private ?array $options = null;

    public function __construct(
        private readonly SmailyClientProvider $clientProvider,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly Logger $logger
    ) {
    }

    /**
     * @inheritDoc
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $this->options = [['value' => '', 'label' => __('-- Not Selected --')]];

        try {
            $client = $this->clientProvider->forStore($this->resolveScopeStoreId());
            foreach ($client->getAutomationWorkflows() as $workflow) {
                $this->options[] = [
                    'value' => (string)$workflow['id'],
                    'label' => sprintf('%s (%d)', $workflow['title'], $workflow['id']),
                ];
            }
        } catch (SmailyClientException $exception) {
            $this->logger->debug('Workflow list unavailable in admin', ['error' => $exception->getMessage()]);
        }

        return $this->options;
    }

    /**
     * Map the website scope being edited to a representative store view for
     * credential resolution.
     */
    private function resolveScopeStoreId(): ?int
    {
        $websiteId = $this->request->getParam('website');
        if (!$websiteId) {
            return null;
        }

        foreach ($this->storeManager->getStores() as $store) {
            if ((int)$store->getWebsiteId() === (int)$websiteId) {
                return (int)$store->getId();
            }
        }

        return null;
    }
}
