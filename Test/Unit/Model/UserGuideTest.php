<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Adminhtml\SetupNotice;
use Smaily\Connect\Model\Engine\ConsentSource;
use Smaily\Connect\Model\UserGuide;

/**
 * The user guide's address is set in one place, and the section the admin
 * deep-links to exists on the guide site.
 */
class UserGuideTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testEveryAdminLinkUsesTheOneAddress(): void
    {
        self::assertSame(UserGuide::URL, SetupNotice::URL);
        self::assertSame(UserGuide::URL, SetupNotice::ALL_URLS[0]);
        self::assertNotContains(UserGuide::URL, SetupNotice::PREVIOUS_URLS);
        self::assertSame(UserGuide::URL . '#' . UserGuide::SECTION_CONSENT_TOOL, ConsentSource::GUIDE_URL);
    }

    public function testTheInitialSetupLinksTheGuideThroughUserGuide(): void
    {
        $template = (string)file_get_contents(self::ROOT . '/view/adminhtml/templates/wizard/index.phtml');

        self::assertStringContainsString('\\Smaily\\Connect\\Model\\UserGuide::URL', $template);
        self::assertStringNotContainsString('USER_GUIDE.md', $template);
    }

    public function testTheConsentToolSectionIsInTheGuideSite(): void
    {
        $site = (string)file_get_contents(self::ROOT . '/docs/site/index.html');

        self::assertStringContainsString('id="' . UserGuide::SECTION_CONSENT_TOOL . '"', $site);
    }
}
