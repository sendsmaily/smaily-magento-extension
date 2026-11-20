<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

/**
 * The merchant user guide: the one place its address is set. Every admin
 * link to the guide resolves through it — the initial setup's last step,
 * the "ready to set up" admin notice (Adminhtml\SetupNotice::URL) and the
 * consent-tool links (Engine\ConsentSource::GUIDE_URL).
 *
 * The guide is the bilingual page docs/site/index.html, published at
 * https://smaily.com/connect-magento/. Moving the URL is this one change plus
 * one line in SetupNotice::PREVIOUS_URLS (the old address), because a store
 * keeps the notice the install wrote with the old link.
 *
 * A section anchor below is an id on the site page.
 */
class UserGuide
{
    public const URL = 'https://smaily.com/connect-magento/';

    /** Section "Step 1 — Connect your Smaily account": where the API credentials are created. */
    public const SECTION_SETUP_CONNECT = 'setup-connect';

    /** Section "Connecting your cookie consent tool". */
    public const SECTION_CONSENT_TOOL = 'connecting-your-cookie-consent-tool';

    /** Section "Recommendations on your store's pages" (the Smaily recommendations widget). */
    public const SECTION_STOREFRONT_RECOMMENDATIONS = 'set-storefront-recs';
}
