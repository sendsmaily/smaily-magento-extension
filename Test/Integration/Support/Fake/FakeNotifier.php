<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support\Fake;

use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\Notification\NotifierInterface;

/**
 * Recording admin notifier: captures notifications for assertions.
 */
class FakeNotifier implements NotifierInterface
{
    /**
     * @var array<int, array{severity: int, title: string, description: string}>
     */
    private $notifications = [];

    /**
     * All captured notifications, in order.
     *
     * @return array<int, array{severity: int, title: string, description: string}>
     */
    public function getNotifications(): array
    {
        return $this->notifications;
    }

    /**
     * Drop captured notifications (test isolation).
     */
    public function reset(): void
    {
        $this->notifications = [];
    }

    /**
     * @inheritDoc
     */
    public function add($severity, $title, $description, $url = '', $isInternal = true)
    {
        $this->notifications[] = [
            'severity' => (int)$severity,
            'title' => (string)$title,
            'description' => (string)$description,
        ];

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function addCritical($title, $description, $url = '', $isInternal = true)
    {
        return $this->add(MessageInterface::SEVERITY_CRITICAL, $title, $description, $url, $isInternal);
    }

    /**
     * @inheritDoc
     */
    public function addMajor($title, $description, $url = '', $isInternal = true)
    {
        return $this->add(MessageInterface::SEVERITY_MAJOR, $title, $description, $url, $isInternal);
    }

    /**
     * @inheritDoc
     */
    public function addMinor($title, $description, $url = '', $isInternal = true)
    {
        return $this->add(MessageInterface::SEVERITY_MINOR, $title, $description, $url, $isInternal);
    }

    /**
     * @inheritDoc
     */
    public function addNotice($title, $description, $url = '', $isInternal = true)
    {
        return $this->add(MessageInterface::SEVERITY_NOTICE, $title, $description, $url, $isInternal);
    }
}
