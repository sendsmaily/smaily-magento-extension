<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Adminhtml;

use Smaily\Connect\Model\Adminhtml\SetupNotice;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * PRO-3628: once the initial setup is finished, the "ready to set up" admin
 * notice is marked read; other notices keep their state.
 */
class SetupNoticeTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new SchemaInstaller($this->connection))->createAdminNotificationInbox();
        $this->connection->insert('adminnotification_inbox', [
            'title' => 'Smaily Connect is ready to set up',
            'url' => SetupNotice::URL,
        ]);
        $this->connection->insert('adminnotification_inbox', [
            'title' => 'Smaily Connect upgrade',
            'url' => null,
        ]);
    }

    protected function tearDown(): void
    {
        $this->connection->query('DROP TABLE IF EXISTS `adminnotification_inbox`');
        parent::tearDown();
    }

    public function testMarkReadMarksOnlyTheSetupNoticeRead(): void
    {
        $this->objectManager->create(SetupNotice::class)->markRead();

        self::assertSame(
            ['Smaily Connect is ready to set up' => '1', 'Smaily Connect upgrade' => '0'],
            $this->connection->fetchPairs(
                $this->connection->select()->from('adminnotification_inbox', ['title', 'is_read'])
            )
        );
    }

    /**
     * Magento_AdminNotification may be disabled: finishing the setup still works.
     */
    public function testMarkReadWithoutTheNotificationTableDoesNothing(): void
    {
        $this->connection->query('DROP TABLE `adminnotification_inbox`');

        $this->objectManager->create(SetupNotice::class)->markRead();

        self::assertFalse($this->connection->isTableExists('adminnotification_inbox'));
    }
}
