<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Adminhtml;

use Smaily\Connect\Model\Adminhtml\DashboardStats;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\Queue\Handler\ContactSyncHandler;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * The Dashboard's counters against real queue tables: "Queued today"
 * (PRO-3628) and the two "delivered, last 30 days" tiles (PRO-3634).
 */
class DashboardStatsTest extends IntegrationTestCase
{
    private int $sequence = 0;

    /**
     * The tile's caption is "events waiting to send": it counts the rows
     * queued today that are still waiting — first attempt or a retry — on
     * both queues, and no row that was sent, failed or is being sent now.
     */
    public function testQueuedTodayCountsOnlyTheRowsTodayThatAreStillWaiting(): void
    {
        $today = $this->clockDate();
        $yesterday = gmdate('Y-m-d 23:59:59', $this->clock->now() - 86400);

        $this->smailyRow('pending', $today);
        $this->smailyRow('pending', $today, 2);
        $this->smailyRow('sent', $today);
        $this->smailyRow('failed', $today);
        $this->smailyRow('sending', $today);
        $this->smailyRow('pending', $yesterday);
        $this->ingestRow('pending', $today);
        $this->ingestRow('sent', $today);
        $this->ingestRow('pending', $yesterday);

        self::assertSame(3, $this->objectManager->create(DashboardStats::class)->queuedToday());
    }

    /**
     * PRO-3634: the Smaily tile counts a contact sync only when it reached
     * Smaily. A row the queue closed without sending (Skipped) or withdrew
     * is stored as sent too, and is not counted.
     */
    public function testContactSyncsDeliveredCountsOnlyTheRowsThatReachedSmaily(): void
    {
        $today = $this->clockDate();

        $this->smailyRow('sent', $today);
        $this->smailyRow('sent', $today, 2);
        $this->smailyRow('sent', $today, 0, ['last_error' => ContactSyncHandler::SKIPPED_NOT_A_CONTACT]);
        $this->smailyRow('sent', $today, 0, ['last_response' => EventQueue::CANCELLED_RESPONSE]);
        $this->smailyRow('sent', $today, 0, ['event_type' => EventType::AUTOMATION_TRIGGER]);
        $this->smailyRow('pending', $today);
        $this->smailyRow('failed', $today);

        self::assertSame(2, $this->objectManager->create(DashboardStats::class)->contactSyncsDelivered());
    }

    /**
     * PRO-3634: the Campaign Intelligence tile counts a catalog item only
     * when the engine accepted it: a row waiting, being sent or failed is
     * not counted, nor is a product removal (its own domain).
     */
    public function testCatalogItemsDeliveredCountsOnlyTheRowsTheEngineAccepted(): void
    {
        $today = $this->clockDate();

        $this->ingestRow('sent', $today);
        $this->ingestRow('sent', $today);
        $this->ingestRow('pending', $today);
        $this->ingestRow('sending', $today);
        $this->ingestRow('failed', $today);
        $this->ingestRow('sent', $today, 'catalog_remove');

        self::assertSame(2, $this->objectManager->create(DashboardStats::class)->catalogItemsDelivered());
    }

    /**
     * PRO-3642: recent activity reads each row's status as the Log does —
     * a row closed without sending is Skipped, a withdrawn one Withdrawn
     * (withdrawn wins over an earlier attempt's error), not Sent.
     */
    public function testRecentActivityReadsSkippedAndWithdrawnAsTheLogDoes(): void
    {
        $today = $this->clockDate();

        $this->smailyRow('sent', $today);
        $this->smailyRow('sent', $today, 0, ['last_error' => ContactSyncHandler::SKIPPED_NOT_A_CONTACT]);
        $this->smailyRow('sent', $today, 1, [
            'last_response' => EventQueue::CANCELLED_RESPONSE,
            'last_error' => 'Connection timed out',
        ]);
        $this->smailyRow('failed', $today, 5, ['last_error' => 'boom']);
        $this->ingestRow('sent', $today);

        $statuses = [];
        foreach ($this->objectManager->create(DashboardStats::class)->recentActivity() as $row) {
            $statuses[$row['source']][] = $row['status'];
        }
        sort($statuses['smaily']);

        self::assertSame(
            [
                'smaily' => [
                    Event::STATUS_FAILED,
                    Event::STATUS_SENT,
                    Collection::STATUS_SKIPPED,
                    Collection::STATUS_WITHDRAWN,
                ],
                'intelligence' => ['sent'],
            ],
            $statuses
        );
    }

    /**
     * PRO-3765: a profiling-consent row's Entity is the shopper's keyed
     * hash, shown short as in the Log; a contact sync's as stored.
     */
    public function testRecentActivityShowsAConsentRowsEntityAsTheLogDoes(): void
    {
        $today = $this->clockDate();
        $hash = hash_hmac('sha256', 'u1@example.invalid', 'integration-test-key');

        $this->smailyRow('pending', $today, 0, [
            'event_type' => EventType::ENGINE_PROFILING_CONSENT,
            'entity_id' => $hash,
        ]);
        $this->smailyRow('pending', $today);

        $entities = array_column(
            $this->objectManager->create(DashboardStats::class)->recentActivity(),
            'entity_id',
            'type'
        );

        self::assertSame(substr($hash, 0, 12) . '…', $entities[EventType::ENGINE_PROFILING_CONSENT]);
        self::assertSame('test@example.com', $entities['contact.sync']);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function smailyRow(string $status, string $createdAt, int $attempts = 0, array $extra = []): void
    {
        $this->connection->insert(EventResource::TABLE_NAME, $extra + [
            'event_type' => 'contact.sync',
            'entity_id' => 'test@example.com',
            'event_uuid' => 'dash-' . ++$this->sequence,
            'payload' => '{}',
            'status' => $status,
            'attempts' => $attempts,
            'created_at' => $createdAt,
        ]);
    }

    private function ingestRow(string $status, string $createdAt, string $domain = 'catalog'): void
    {
        $this->connection->insert(IngestEventResource::TABLE_NAME, [
            'domain' => $domain,
            'entity_id' => '1',
            'event_uuid' => 'dash-' . ++$this->sequence,
            'payload' => '{}',
            'status' => $status,
            'created_at' => $createdAt,
        ]);
    }
}
