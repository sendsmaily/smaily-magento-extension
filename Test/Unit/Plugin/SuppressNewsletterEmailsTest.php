<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Plugin;

use Magento\Newsletter\Model\Subscriber;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Plugin\SuppressNewsletterEmails;

/**
 * PRO-4040: Magento's own newsletter emails are suppressed only while the
 * website's contact sync is active — before that a new subscriber never
 * reaches Smaily, so Magento's email is the only one they would get.
 */
class SuppressNewsletterEmailsTest extends TestCase
{
    /**
     * @dataProvider casesProvider
     */
    public function testMagentoEmailsAreSuppressedOnlyWhileContactSyncIsActive(
        bool $syncActive,
        bool $suppress,
        bool $expected
    ): void {
        $config = $this->createMock(Config::class);
        $config->method('isConnected')->with(5)->willReturn(true);
        $config->method('isContactSyncActive')->with(2)->willReturn($syncActive);
        $config->method('suppressOptinEmails')->with(2)->willReturn($suppress);
        $dispatcher = $this->createMock(SyncDispatcher::class);
        $dispatcher->method('websiteId')->with(5)->willReturn(2);
        $plugin = new SuppressNewsletterEmails($config, $dispatcher);

        $subscribed = $this->subscriber();
        $plugin->beforeSendConfirmationSuccessEmail($subscribed);
        $unsubscribed = $this->subscriber();
        $plugin->beforeSendUnsubscriptionEmail($unsubscribed);

        self::assertSame($expected, (bool)$subscribed->getImportMode());
        self::assertSame($expected, (bool)$unsubscribed->getImportMode());
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: bool}>
     */
    public static function casesProvider(): array
    {
        return [
            'contact sync active, suppression on' => [true, true, true],
            'contact sync not active, suppression on' => [false, true, false],
            'contact sync active, suppression off' => [true, false, false],
        ];
    }

    private function subscriber(): Subscriber
    {
        $subscriber = $this->getMockBuilder(Subscriber::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $subscriber->setData('store_id', 5);

        return $subscriber;
    }
}
