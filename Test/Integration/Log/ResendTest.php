<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Log;

use Smaily\Connect\Model\Log\QueueRowLoader;
use Smaily\Connect\Model\Log\Resend;
use Smaily\Connect\Model\Log\ResendGuard;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * "Send again" against a real smaily_event_queue table: the new row is the
 * attempt AND the audit record, the failed row stays as history, and the
 * guard's refusals are read off rows the store itself wrote.
 */
class ResendTest extends IntegrationTestCase
{
    private EventQueue $queue;
    private QueueRowLoader $rowLoader;
    private Resend $resend;
    private ResendGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(EventQueue::class);
        $this->rowLoader = $this->objectManager->create(QueueRowLoader::class);
        $this->resend = $this->objectManager->create(Resend::class);
        $this->guard = $this->objectManager->create(ResendGuard::class);
    }

    public function testResendQueuesANewRowAndKeepsTheFailedOneAsHistory(): void
    {
        $payload = ['trigger_type' => 'welcome', 'address' => ['email' => 'jane@example.com']];
        $this->queue->enqueue(EventType::AUTOMATION_TRIGGER, $payload, 'jane@example.com', 1, 'u-1');
        $this->markFailed(1);

        $row = $this->rowLoader->load('smaily-1');
        self::assertIsArray($row);
        self::assertTrue($this->resend->resend($row, 'erkki'));

        $rows = $this->fetchAll(EventResource::TABLE_NAME);
        self::assertCount(2, $rows);

        self::assertSame(Event::STATUS_FAILED, $rows[0]['status'], 'The failed row stays as history');
        self::assertSame('boom', $rows[0]['last_error']);
        self::assertSame($payload, json_decode((string)$rows[0]['payload'], true));

        self::assertSame(Event::STATUS_PENDING, $rows[1]['status']);
        self::assertSame(EventType::AUTOMATION_TRIGGER, $rows[1]['event_type']);
        self::assertSame('jane@example.com', $rows[1]['entity_id']);
        self::assertSame('1', (string)$rows[1]['website_id']);
        self::assertNotSame($rows[0]['event_uuid'], $rows[1]['event_uuid']);

        $stored = json_decode((string)$rows[1]['payload'], true);
        self::assertSame($payload['trigger_type'], $stored['trigger_type']);
        self::assertSame(1, $stored[Resend::PAYLOAD_KEY]['of']);
        self::assertSame('erkki', $stored[Resend::PAYLOAD_KEY]['by']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $stored[Resend::PAYLOAD_KEY]['at']
        );

        $record = $this->resend->recordOf($stored);
        self::assertSame(
            [
                'of' => 1,
                'by' => 'erkki',
                // Handed over as the plain UTC string the drawer formats.
                'at' => gmdate('Y-m-d H:i:s', (int)strtotime($stored[Resend::PAYLOAD_KEY]['at'])),
            ],
            $record
        );
    }

    public function testTheResendRecordNeverReachesSmaily(): void
    {
        $this->queue->enqueue(EventType::AUTOMATION_TRIGGER, ['trigger_type' => 'welcome'], 'jane@example.com', 0, 'u-1');
        $this->markFailed(1);
        $this->resend->resend((array)$this->rowLoader->load('smaily-1'), 'erkki');

        $claimed = $this->queue->claimBatch();
        self::assertCount(1, $claimed);
        self::assertSame(
            ['trigger_type' => 'welcome'],
            $this->queue->decodePayload($claimed[0]),
            'The flusher must never see the resend record'
        );
    }

    public function testGuardRefusesAWithdrawnRow(): void
    {
        $this->queue->enqueue(EventType::AUTOMATION_TRIGGER, ['trigger_type' => 'abandoned_cart'], 'jane@example.com', 0, 'u-1');
        $this->queue->cancelPendingAutomation('abandoned_cart', 'jane@example.com');

        $row = (array)$this->rowLoader->load('smaily-1');

        self::assertSame(
            ResendGuard::REASON_WITHDRAWN,
            $this->guard->refusalReason(Collection::SOURCE_SMAILY, 1, $row)
        );
    }

    public function testGuardRefusesAnAutomationRowALaterDeliveryAlreadySuperseded(): void
    {
        $this->queue->enqueue(EventType::AUTOMATION_TRIGGER, ['trigger_type' => 'welcome'], 'jane@example.com', 0, 'u-1');
        $this->markFailed(1);
        $this->queue->enqueue(EventType::AUTOMATION_TRIGGER, ['trigger_type' => 'welcome'], 'jane@example.com', 0, 'u-2');
        // A delivery keeps the request it sent (PRO-3642: the Log's delivered rule).
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['status' => Event::STATUS_SENT, 'sent_payload' => '[{"email":"jane@example.com"}]'],
            ['id = ?' => 2]
        );

        self::assertSame(
            ResendGuard::REASON_SUPERSEDED,
            $this->guard->refusalReason(
                Collection::SOURCE_SMAILY,
                1,
                (array)$this->rowLoader->load('smaily-1')
            )
        );
        self::assertSame(
            [1 => ResendGuard::REASON_SUPERSEDED],
            $this->guard->refusalReasons(
                Collection::SOURCE_SMAILY,
                $this->rowLoader->loadFailed(Collection::SOURCE_SMAILY, [1, 2])
            ),
            'The mass retry skips exactly the superseded row'
        );
    }

    public function testGuardClearsAnAutomationRowOfAnotherTrigger(): void
    {
        $this->queue->enqueue(EventType::AUTOMATION_TRIGGER, ['trigger_type' => 'welcome'], 'jane@example.com', 0, 'u-1');
        $this->markFailed(1);
        $this->queue->enqueue(EventType::AUTOMATION_TRIGGER, ['trigger_type' => 'abandoned_cart'], 'jane@example.com', 0, 'u-2');
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['status' => Event::STATUS_SENT],
            ['id = ?' => 2]
        );

        self::assertSame(
            '',
            $this->guard->refusalReason(
                Collection::SOURCE_SMAILY,
                1,
                (array)$this->rowLoader->load('smaily-1')
            )
        );
    }

    /**
     * PRO-3642: a later row of the same trigger that was skipped or
     * withdrawn reached nobody, so it does not stand in the way of sending
     * the failed one again — the same delivered rule as the Log.
     */
    public function testALaterSkippedOrWithdrawnRowDoesNotSupersede(): void
    {
        $this->queue->enqueue(EventType::AUTOMATION_TRIGGER, ['trigger_type' => 'abandoned_cart'], 'jane@example.com', 0, 'u-1');
        $this->markFailed(1);
        $this->queue->enqueue(EventType::AUTOMATION_TRIGGER, ['trigger_type' => 'abandoned_cart'], 'jane@example.com', 0, 'u-2');
        $claimed = $this->queue->claimBatch();
        $this->queue->markSkipped($claimed[0], 'Skipped: no Smaily workflow is mapped to this automation trigger.');
        $this->queue->enqueue(EventType::AUTOMATION_TRIGGER, ['trigger_type' => 'abandoned_cart'], 'jane@example.com', 0, 'u-3');
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['sent_payload' => '[{"email":"jane@example.com"}]', 'last_response' => '{"http_status":503}'],
            ['id = ?' => 3]
        );
        $this->queue->cancelPendingAutomation('abandoned_cart', 'jane@example.com');

        self::assertSame(
            '',
            $this->guard->refusalReason(
                Collection::SOURCE_SMAILY,
                1,
                (array)$this->rowLoader->load('smaily-1')
            )
        );
    }

    private function markFailed(int $id): void
    {
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['status' => Event::STATUS_FAILED, 'attempts' => 1, 'last_error' => 'boom'],
            ['id = ?' => $id]
        );
    }
}
