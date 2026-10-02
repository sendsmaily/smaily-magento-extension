<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\ViewModel;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\ViewModel\PrivacyForm;

/**
 * PRO-3591: My Account > Personalization shows the shopper's choice only
 * when the store knows it — never the fail-open default as if it were theirs.
 */
class PrivacyFormTest extends TestCase
{
    /**
     * @return array<string, array{0: ?bool}>
     */
    public static function preferences(): array
    {
        return ['allowed' => [true], 'opted out' => [false], 'not known' => [null]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('preferences')]
    public function testThePageShowsWhatTheStoreKnows(?bool $known): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('person@example.com');
        $customer->method('getStoreId')->willReturn(1);
        $session = $this->createMock(CustomerSession::class);
        $session->method('isLoggedIn')->willReturn(true);
        $session->method('getCustomerData')->willReturn($customer);

        $consent = $this->createMock(ProfilingConsent::class);
        $consent->expects(self::once())->method('knownPreference')
            ->with('person@example.com', 1)->willReturn($known);
        $consent->expects(self::never())->method('isAllowed');

        self::assertSame($known, (new PrivacyForm($session, $consent))->getKnownPreference());
    }
}
