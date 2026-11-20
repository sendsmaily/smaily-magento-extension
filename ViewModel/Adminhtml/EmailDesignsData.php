<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Model\Engine\EmailDesigns;
use Smaily\Connect\Model\Engine\IntelligenceStart;
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
     * Whether the design page may open in a frame over this admin: an admin
     * on https. The engine frames it for the origin of the site URL the
     * extension gives it as `plugin_info.site_url` (§22 Embedding rule 1
     * (a)), and for an admin origin of its own that the link names as
     * `frame_origin` (rule 1 (d), contract 1.31.0, CON-366) when it is in
     * the site's registrable domain; a refused origin falls back to a new
     * tab. An http admin opens the page in a new tab. Read from the request
     * as the admin's browser sent it, so a TLS proxy counts as Magento
     * counts it (Http::isSecure()).
     */
    public function isFrameable(): bool
    {
        return IntelligenceStart::isFrameable(EmailDesigns::requestOrigin($this->request));
    }
}
