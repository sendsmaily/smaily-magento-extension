<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\EmailDesignWorkflows;
use Smaily\Connect\Model\Engine\EmailDesigns;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Settings;

/**
 * CON-257: the §23 reader behind "Edit design" and the §22 link minting.
 */
class EmailDesignsTest extends TestCase
{
    /** @var array<int, array<string, mixed>> the bodies the engine was sent */
    private array $bodies = [];

    public function testTheListKeepsEveryKnownKindAndLeavesTheRestOut(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('emailDesigns')->willReturn(['emails' => [
            [
                'email_key' => 'plugin.abandoned_cart',
                'kind' => 'plugin',
                'design_state' => 'edited',
                'subject' => 'You left something in your cart',
                'smaily_workflow_id' => '456',
            ],
            ['email_key' => 'transactional.order_confirmation', 'kind' => 'transactional', 'design_state' => 'none', 'subject' => null],
            ['email_key' => 'replenish_due', 'kind' => 'engine', 'design_state' => 'starter'],
            ['email_key' => 'plugin.future', 'kind' => 'newsletter', 'design_state' => 'none'],
            ['email_key' => '../x', 'kind' => 'plugin'],
            ['kind' => 'plugin'],
            'not an item',
        ]]);

        self::assertSame([
            [
                'email_key' => 'plugin.abandoned_cart',
                'kind' => 'plugin',
                'design_state' => 'edited',
                'subject' => 'You left something in your cart',
            ],
            [
                'email_key' => 'transactional.order_confirmation',
                'kind' => 'transactional',
                'design_state' => 'none',
                'subject' => '',
            ],
            ['email_key' => 'replenish_due', 'kind' => 'engine', 'design_state' => 'starter', 'subject' => ''],
        ], $this->emailDesigns($client)->designs());
    }

    public function testAListWithoutEmailsIsEmpty(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('emailDesigns')->willReturn(['error' => 'nothing']);

        self::assertSame([], $this->emailDesigns($client)->designs());
    }

    public function testTheLinkCarriesTheReturnUrlOnTheSiteHostAndTheLanguage(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('siteUrl')->willReturn('https://Shop.example/');
        $client->expects(self::once())->method('emailDesignLink')
            ->with('plugin.welcome', [
                'return_url' => 'https://shop.example/admin/smaily/settings/',
                'language' => 'et',
                'display' => 'tab',
            ])
            ->willReturn(['url' => 'https://intelligence.smaily.com/design/email/dl_1', 'expires_at' => 'x']);

        self::assertSame(
            'https://intelligence.smaily.com/design/email/dl_1',
            $this->emailDesigns($client)->designLink(
                'plugin.welcome',
                'https://shop.example/admin/smaily/settings/',
                'et',
                false,
                'https://shop.example'
            )
        );
    }

    /**
     * CON-366, contract 1.31.0 §22: a link for the modal is a frame link,
     * whose session lives in the page, not in a third-party cookie. An admin
     * on the shop's own origin needs no `frame_origin` (Embedding rule 1 (a)).
     */
    public function testAFrameLinkFromTheShopsOwnOriginNamesNoFrameOrigin(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('siteUrl')->willReturn('https://shop.example/');
        $client->expects(self::once())->method('emailDesignLink')
            ->with('plugin.welcome', ['language' => 'en', 'display' => 'frame'])
            ->willReturn(['url' => 'https://intelligence.smaily.com/design/email/dl_1']);

        $this->emailDesigns($client)->designLink('plugin.welcome', '', 'en', true, 'https://shop.example');
    }

    /**
     * An admin on a host or port of its own names its origin as
     * `frame_origin` (Embedding rule 1 (d)); a tab link never carries it.
     */
    public function testAFrameLinkFromAnAdminOnItsOwnOriginNamesThatOrigin(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('siteUrl')->willReturn('https://www.shop.example/');
        $client->expects(self::exactly(2))->method('emailDesignLink')
            ->willReturnCallback(function (string $emailKey, array $body): array {
                $this->bodies[] = $body;

                return ['url' => 'https://intelligence.smaily.com/design/email/dl_1'];
            });

        $this->emailDesigns($client)->designLink('plugin.welcome', '', 'en', true, 'https://admin.shop.example');
        $this->emailDesigns($client)->designLink('plugin.welcome', '', 'en', false, 'https://admin.shop.example');

        self::assertSame([
            ['language' => 'en', 'display' => 'frame', 'frame_origin' => 'https://admin.shop.example'],
            ['language' => 'en', 'display' => 'tab'],
        ], $this->bodies);
    }

    /**
     * @dataProvider frameOriginRefusalProvider
     * @param array<string, mixed> $errorBody
     */
    public function testARefusedFrameOriginIsA422NamingIt(int $status, array $errorBody, bool $refused): void
    {
        self::assertSame($refused, EmailDesigns::refusesFrameOrigin($status, $errorBody));
    }

    /**
     * @return array<string, array{int, array<string, mixed>, bool}>
     */
    public static function frameOriginRefusalProvider(): array
    {
        $frameOrigin = ['error' => 'validation_failed', 'errors' => [
            ['field' => 'return_url', 'message' => 'x'],
            ['field' => 'frame_origin', 'message' => 'not in the registrable domain of the site'],
        ]];

        return [
            'frame_origin refused' => [422, $frameOrigin, true],
            'another field' => [422, ['error' => 'validation_failed', 'errors' => [['field' => 'language']]], false],
            'no errors' => [422, ['error' => 'validation_failed'], false],
            'another status' => [400, $frameOrigin, false],
        ];
    }

    /**
     * @dataProvider refusedReturnUrlProvider
     */
    public function testAReturnUrlTheEngineWouldRefuseIsLeftOut(string $returnUrl): void
    {
        $client = $this->createMock(Client::class);
        $client->method('siteUrl')->willReturn('https://shop.example/');
        $client->expects(self::once())->method('emailDesignLink')->with('plugin.welcome', ['display' => 'tab'])
            ->willReturn(['url' => 'https://intelligence.smaily.com/design/email/dl_1']);

        $this->emailDesigns($client)->designLink('plugin.welcome', $returnUrl, '', false, '');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedReturnUrlProvider(): array
    {
        return [
            'another host' => ['https://admin.shop.example/admin/'],
            'not https' => ['http://shop.example/admin/'],
            'too long' => ['https://shop.example/' . str_repeat('a', 2048)],
            'empty' => [''],
        ];
    }

    public function testALinkThatIsNotHttpsIsNeverPassedOn(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('emailDesignLink')->willReturn(['url' => 'javascript:alert(1)']);

        $this->expectException(EngineRequestException::class);
        $this->emailDesigns($client)->designLink('plugin.welcome', '', 'en', false, '');
    }

    public function testAKeyThatIsNoEmailKeyIsNeverSent(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('emailDesignLink');

        $this->expectException(EngineRequestException::class);
        $this->emailDesigns($client)->designLink('plugin.Welcome/../x', '', 'en', false, '');
    }

    public function testOnlyAnAccountTheEngineMayBeAskedForIsAvailable(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isSendingAllowed')->willReturn(false);

        self::assertFalse((new EmailDesigns(
            $settings,
            $this->createMock(Client::class),
            $this->createMock(EmailDesignWorkflows::class)
        ))->isAvailable());
    }

    public function testEmailKeys(): void
    {
        self::assertTrue(EmailDesigns::isEmailKey('plugin.first_order'));
        self::assertTrue(EmailDesigns::isEmailKey('transactional.shipping_confirmation'));
        self::assertTrue(EmailDesigns::isEmailKey('replenish_due'));
        self::assertFalse(EmailDesigns::isEmailKey('plugin.'));
        self::assertFalse(EmailDesigns::isEmailKey('other.welcome'));
        self::assertFalse(EmailDesigns::isEmailKey('Replenish'));
        self::assertTrue(EmailDesigns::isEngineEmail('replenish_due'));
        self::assertFalse(EmailDesigns::isEngineEmail('plugin.welcome'));
    }

    public function testOriginsAreWhatTheBrowserReports(): void
    {
        self::assertSame('https://intelligence.smaily.com', EmailDesigns::origin('https://intelligence.smaily.com/'));
        self::assertSame('https://shop.example', EmailDesigns::origin('https://Shop.example:443/de/'));
        self::assertSame('https://shop.example:8443', EmailDesigns::origin('https://shop.example:8443/'));
        self::assertSame('', EmailDesigns::origin(''));
    }

    /**
     * CON-258: every successful read hands the whole §23 list to the store
     * of pushed workflows — engine items and ids included; it picks.
     */
    public function testEveryReadKeepsThePushedWorkflows(): void
    {
        $emails = [
            ['email_key' => 'plugin.welcome', 'kind' => 'plugin', 'smaily_workflow_id' => '456'],
            ['email_key' => 'replenish_due', 'kind' => 'engine'],
        ];
        $client = $this->createMock(Client::class);
        $client->method('emailDesigns')->willReturn(['emails' => $emails]);
        $workflows = $this->createMock(EmailDesignWorkflows::class);
        $workflows->expects(self::once())->method('remember')->with($emails);

        $this->emailDesigns($client, $workflows)->designs();
    }

    /**
     * CON-258: a read without a list, or one that fails, keeps what the last
     * read stored (as the WooCommerce plugin does).
     */
    public function testAReadWithoutAListKeepsThePushedWorkflows(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('emailDesigns')->willReturnOnConsecutiveCalls(
            ['error' => 'nothing'],
            self::throwException(new EngineRequestException('unavailable', 503, []))
        );
        $workflows = $this->createMock(EmailDesignWorkflows::class);
        $workflows->expects(self::never())->method('remember');
        $emailDesigns = $this->emailDesigns($client, $workflows);

        $emailDesigns->designs();
        $this->expectException(EngineRequestException::class);
        $emailDesigns->designs();
    }

    private function emailDesigns(Client $client, ?EmailDesignWorkflows $workflows = null): EmailDesigns
    {
        return new EmailDesigns(
            $this->createMock(Settings::class),
            $client,
            $workflows ?? $this->createMock(EmailDesignWorkflows::class)
        );
    }
}
