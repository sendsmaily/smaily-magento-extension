<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Model\Report\ReportBuilder;
use Smaily\Connect\Model\UserGuide;

/**
 * The invitation to a store with no Smaily account (CON-364, as the
 * WooCommerce plugin's CON-321 / CON-351): the banner
 * (panel/smaily-invitation-banner.phtml) on setup step 1 and the Dashboard,
 * and the admin system message on every page (Adminhtml\SmailyInvitationNotice).
 *
 * Due while no Smaily marketing account is connected — the installation
 * report's rule, ReportBuilder::mainCredentials(), so a Transactional
 * account alone does not count — and only for an admin who may open the
 * extension's Settings. Connecting an account removes it at once. The
 * Dashboard banner and the system message share one Dismiss
 * (Controller\Adminhtml\Invitation\Dismiss), kept as a time at the default
 * scope: it hides both for DISMISS_SECONDS for every admin. The banner on
 * setup step 1 cannot be dismissed.
 */
class SmailyInvitationData implements ArgumentInterface
{
    /** When the invitation was last dismissed (a Unix time), default scope. */
    public const XML_PATH_DISMISSED_AT = 'smaily_connect/internal/invitation_dismissed_at';

    /** A dismissed invitation stays away this long (Woo CON-321). */
    public const DISMISS_SECONDS = 90 * 86400;

    /** Smaily sign-up: where "Create a Smaily account" leads (Woo's SIGNUP_URL). */
    public const SIGNUP_URL = 'https://www.sendsmaily.net';

    /** The admins who may connect an account: the Settings resource. */
    public const RESOURCE = 'Smaily_Connect::config';

    private ?bool $due = null;

    public function __construct(
        private readonly ReportBuilder $reportBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DateTime $dateTime,
        private readonly AuthorizationInterface $authorization,
        private readonly UrlInterface $urlBuilder,
        private readonly FormKey $formKey
    ) {
    }

    /** This admin may connect an account, and no Smaily marketing account is connected. */
    public function isDue(): bool
    {
        return $this->due ??= $this->authorization->isAllowed(self::RESOURCE)
            && $this->reportBuilder->mainCredentials() === null;
    }

    /** The Dashboard banner and the system message were dismissed in the last DISMISS_SECONDS. */
    public function isDismissed(): bool
    {
        $at = (int)$this->scopeConfig->getValue(self::XML_PATH_DISMISSED_AT);

        return $at > 0 && (int)$this->dateTime->gmtTimestamp() - $at < self::DISMISS_SECONDS;
    }

    public function getSignupUrl(): string
    {
        return self::SIGNUP_URL;
    }

    /** "Connect your Smaily account" away from setup step 1: Settings > Connection (the setup until it is finished). */
    public function getConnectUrl(): string
    {
        return $this->urlBuilder->getUrl('smaily_connect/settings', ['_query' => ['tab' => 'connection']]);
    }

    /** The guide's Step 1, which says where the API credentials are created. */
    public function getGuideUrl(): string
    {
        return UserGuide::URL . '#' . UserGuide::SECTION_SETUP_CONNECT;
    }

    /**
     * Dismiss: a GET link, as Magento's own "Mark as Read" on a notification,
     * with the admin URL's secret key and the form key.
     */
    public function getDismissUrl(): string
    {
        return $this->urlBuilder->getUrl(
            'smaily_connect/invitation/dismiss',
            ['_query' => ['form_key' => $this->formKey->getFormKey()]]
        );
    }
}
