<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Recommendations;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\AbstractResult;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Result\Layout;
use Smaily\Connect\Model\Engine\RecommendedProducts;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\StorefrontRecommendations;
use Smaily\Connect\Model\RateLimit\FixedWindowCounter;

/**
 * The shopper's recommendation cards (GET smaily/recommendations), asked
 * once by the storefront script after the page has loaded, for the empty
 * container the "Smaily recommendations" widget leaves in the page. Answers
 * the cards as server-rendered, escaped HTML (layout handle
 * smaily_recommendations_index, a block that is not cacheable), or an empty
 * body. Who the cards are for comes from server state only
 * (StorefrontRecommendations): nothing in the request names the shopper.
 *
 * Guards, in order (as the WooCommerce plugin's RecommendationsEndpoint):
 *   1. a store that may not call the engine (`isSendingAllowed()`) answers
 *      a bare 404;
 *   2. a per-address limit, as the browse relay's, because every miss in the
 *      per-shopper cache spends an engine call;
 *   3. a request another site makes (`Sec-Fetch-Site: cross-site` or
 *      `same-site`) gets an empty answer: the cards belong to the shopper
 *      whose cookies came with it.
 * Every answer carries `Cache-Control: no-store, private`: it belongs to one
 * shopper and no shared cache may keep it.
 */
class Index implements HttpGetActionInterface
{
    public const BLOCK_NAME = 'smaily.recommendations';

    private const RATE_LIMIT_PER_MINUTE = 120;
    private const CACHE_CONTROL = 'no-store, private';

    public function __construct(
        private readonly HttpRequest $request,
        private readonly ResultFactory $resultFactory,
        private readonly Settings $settings,
        private readonly StorefrontRecommendations $recommendations,
        private readonly RecommendedProducts $products,
        private readonly FixedWindowCounter $counter,
        private readonly DateTime $dateTime,
        private readonly RemoteAddress $remoteAddress
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): AbstractResult
    {
        if (!$this->settings->isSendingAllowed()) {
            return $this->empty(404);
        }
        if (!$this->allowRequest()) {
            return $this->empty(429);
        }
        $site = (string)$this->request->getHeader('Sec-Fetch-Site');
        if ($site === 'cross-site' || $site === 'same-site') {
            return $this->empty(200);
        }

        $items = $this->products->forSlots($this->recommendations->slots());
        if ($items === []) {
            return $this->empty(200);
        }

        /** @var Layout $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_LAYOUT);
        $block = $result->getLayout()->getBlock(self::BLOCK_NAME);
        if (!$block instanceof AbstractBlock) {
            return $this->empty(200);
        }
        $block->setData('items', $items);

        return $result->setHeader('Cache-Control', self::CACHE_CONTROL, true);
    }

    private function empty(int $status): Raw
    {
        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHttpResponseCode($status);
        $result->setContents('');
        $result->setHeader('Cache-Control', self::CACHE_CONTROL, true);

        return $result;
    }

    /**
     * The browse relay's per-address limit, on its own key: the connection's
     * own address, through Magento's RemoteAddress.
     */
    private function allowRequest(): bool
    {
        $ip = (string)$this->remoteAddress->getRemoteAddress();
        $window = intdiv($this->dateTime->gmtTimestamp(), 60);

        return $this->counter->allow('smaily_recs_' . sha1($ip) . '_' . $window, self::RATE_LIMIT_PER_MINUTE, 120);
    }
}
