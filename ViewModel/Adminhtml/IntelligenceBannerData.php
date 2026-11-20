<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Engine\EmailDesigns;
use Smaily\Connect\Model\Engine\IntelligenceStart;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Report\ConnectionHealth;
use Smaily\Connect\Model\Report\ReportBuilder;
use Smaily\Connect\Model\SmailyUrl;

/**
 * What the Campaign Intelligence banner (panel/intelligence-banner.phtml,
 * CON-363, as the WooCommerce plugin's CON-350) needs from the server.
 * Nothing here calls Smaily or the engine: whether the engine takes starts
 * is asked by the page after it has loaded (Api\OnboardingStatus).
 *
 * Which banner, if any:
 *   - Campaign Intelligence connected → none;
 *   - the main Smaily account's last answer was code 227 (its package has
 *     no API access, ConnectionHealth::PLAN_BLOCKED) → the upsell;
 *   - Smaily accepted the account saved for the page's website → the
 *     invitation;
 *   - otherwise none: the Smaily connection is the thing to fix first.
 *
 * The banner on top of the Settings tabs and the Dashboard can be
 * dismissed for DISMISS_SECONDS for every admin (a timestamp at the default
 * scope); the one on setup step 4 and the Intelligence tab cannot.
 */
class IntelligenceBannerData implements ArgumentInterface
{
    public const VARIANT_INVITE = 'invite';
    public const VARIANT_UPSELL = 'upsell';

    /** When the banner was last dismissed (a Unix time), default scope. */
    public const XML_PATH_DISMISSED_AT = 'smaily_connect/internal/ci_banner_dismissed_at';

    public const DISMISS_SECONDS = 30 * 86400;

    /** Smaily's consultation page: where "Contact Smaily" leads (Woo, Erkki 2026-11-20). */
    public const CONSULTATION_URL = 'https://smaily.com/consultation/';

    /** The banner's actions answer the Settings resource. */
    private const RESOURCE = 'Smaily_Connect::config';

    private ?string $variant = null;

    public function __construct(
        private readonly EngineSettings $engineSettings,
        private readonly ReportBuilder $reportBuilder,
        private readonly ConnectionHealth $health,
        private readonly VerifiedCredentials $verifiedCredentials,
        private readonly WebsiteContext $websiteContext,
        private readonly IntelligenceStart $intelligenceStart,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DateTime $dateTime,
        private readonly AuthorizationInterface $authorization,
        private readonly HttpRequest $request
    ) {
    }

    /** VARIANT_INVITE, VARIANT_UPSELL, or '' for no banner. */
    public function getVariant(): string
    {
        return $this->variant ??= $this->variant();
    }

    /** The dismissible copy (Settings top, Dashboard) was dismissed in the last DISMISS_SECONDS. */
    public function isDismissed(): bool
    {
        $at = (int)$this->scopeConfig->getValue(self::XML_PATH_DISMISSED_AT);

        return $at > 0 && (int)$this->dateTime->gmtTimestamp() - $at < self::DISMISS_SECONDS;
    }

    /**
     * Whether the page asks the engine if "Try Intelligence free" shows:
     * the invitation, for a shop that may start itself (one store, one
     * Smaily account, no engine connection).
     */
    public function offersStart(): bool
    {
        return $this->getVariant() === self::VARIANT_INVITE && $this->intelligenceStart->isOffered();
    }

    /** The main Smaily account's own address — the upsell's "Log in to Smaily". */
    public function getLoginUrl(): string
    {
        $main = $this->reportBuilder->mainCredentials();

        return $main !== null && SmailyUrl::isPlainSubdomain($main->subdomain)
            ? SmailyUrl::forSubdomain($main->subdomain) . '/'
            : '';
    }

    public function getConsultationUrl(): string
    {
        return self::CONSULTATION_URL;
    }

    /** The start window may open in a frame over this admin (IntelligenceStart::isFrameable()). */
    public function isFrameable(): bool
    {
        return IntelligenceStart::isFrameable(
            EmailDesigns::origin($this->request->getScheme() . '://' . $this->request->getHttpHost(false))
        );
    }

    private function variant(): string
    {
        if (!$this->authorization->isAllowed(self::RESOURCE) || $this->engineSettings->isConnected()) {
            return '';
        }
        $main = $this->reportBuilder->mainCredentials();
        if ($main !== null
            && $this->health->smaily($main->subdomain)['last_error_code'] === ConnectionHealth::PLAN_BLOCKED
        ) {
            return self::VARIANT_UPSELL;
        }

        return $this->verifiedCredentials->isWebsiteVerified($this->websiteContext->getWebsiteId())
            ? self::VARIANT_INVITE
            : '';
    }
}
