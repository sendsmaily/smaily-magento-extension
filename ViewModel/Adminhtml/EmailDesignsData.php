<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\EmailDesigns;
use Smaily\Connect\Model\Engine\Settings;

/**
 * What the "Edit design" buttons (panel/email-designs.phtml, CON-257) need
 * from the server: whether the engine may be asked at all, the engine's
 * origin (the only origin whose design page messages the admin page
 * accepts, contract §22 Embedding rule 5) and whether this admin may show
 * the design page in a frame (rule 1). Nothing here calls the engine.
 */
class EmailDesignsData implements ArgumentInterface
{
    public function __construct(
        private readonly EmailDesigns $emailDesigns,
        private readonly Settings $settings,
        private readonly Client $client,
        private readonly HttpRequest $request
    ) {
    }

    /** Campaign Intelligence connected and not refused: the buttons may show. */
    public function isAvailable(): bool
    {
        return $this->emailDesigns->isAvailable();
    }

    /** The origin of the engine base URL, e.g. https://intelligence.smaily.com. */
    public function getEngineOrigin(): string
    {
        return EmailDesigns::origin($this->settings->getEngineBaseUrl());
    }

    /**
     * Whether the engine lets this admin frame its design page: it allows
     * only the origin of the site URL the extension gives it as
     * `plugin_info.site_url` (the default store view's address), on https
     * (§22 Embedding rule 1). An admin on another origin — its own admin
     * host, another port, or plain http — opens the page in a new tab.
     * Read from the request as the admin's browser sent it, so a TLS proxy
     * counts as Magento counts it (Http::isSecure()).
     */
    public function isFrameable(): bool
    {
        $siteOrigin = EmailDesigns::origin($this->client->siteUrl());
        $adminOrigin = EmailDesigns::origin($this->request->getScheme() . '://' . $this->request->getHttpHost(false));

        return str_starts_with($siteOrigin, 'https://') && $siteOrigin === $adminOrigin;
    }
}
